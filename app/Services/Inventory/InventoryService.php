<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Quantity-only stock keeping. Movements are an append-only ledger and the
 * balance row is the lock target. Nothing here values stock or posts to the
 * General Ledger.
 */
class InventoryService
{
    /** Largest quantity a DECIMAL(15,4) movement may carry. */
    private const MAX_QUANTITY = '9999999999.9999';

    public function __construct(
        private BranchAccess $branchAccess,
        private AppSettings $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Receive stock. A purchase order reference is optional traceability only;
     * approving a purchase order never receives stock by itself.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(array $data, User $actor): InventoryMovement
    {
        $this->authorize($actor, 'inventory.receive');

        return $this->idempotent($this->idempotencyKey($data), $actor, function () use ($data, $actor): InventoryMovement {
            [$item, $branch, $location] = $this->guardTarget($data['item_id'], $data['branch_id'], $data['location_id'], $actor);
            $quantity = $this->quantity($data['quantity'] ?? null);
            $purchaseOrder = $this->guardPurchaseOrder($data, $branch);

            return $this->post($item, $branch, $location, InventoryMovementType::Receipt, $quantity, $data, $actor, $purchaseOrder);
        });
    }

    /** @param array<string, mixed> $data */
    public function issue(array $data, User $actor): InventoryMovement
    {
        $this->authorize($actor, 'inventory.issue');

        return $this->idempotent($this->idempotencyKey($data), $actor, function () use ($data, $actor): InventoryMovement {
            [$item, $branch, $location] = $this->guardTarget($data['item_id'], $data['branch_id'], $data['location_id'], $actor);
            if (blank($data['issued_to'] ?? null)) {
                throw ValidationException::withMessages(['issued_to' => 'Record who the stock is issued to.']);
            }

            return $this->post($item, $branch, $location, InventoryMovementType::Issue, $this->quantity($data['quantity'] ?? null)->negated(), $data, $actor);
        });
    }

    /**
     * Stock coming back from a recipient.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnStock(array $data, User $actor): InventoryMovement
    {
        $this->authorize($actor, 'inventory.return');

        return $this->idempotent($this->idempotencyKey($data), $actor, function () use ($data, $actor): InventoryMovement {
            [$item, $branch, $location] = $this->guardTarget($data['item_id'], $data['branch_id'], $data['location_id'], $actor);

            return $this->post($item, $branch, $location, InventoryMovementType::Return, $this->quantity($data['quantity'] ?? null), $data, $actor);
        });
    }

    /**
     * Request a stock adjustment. It is stored as pending and leaves the
     * balance untouched until a different user approves it.
     *
     * @param  array<string, mixed>  $data  direction is "increase" or "decrease"
     */
    public function requestAdjustment(array $data, User $actor): InventoryMovement
    {
        $this->authorize($actor, 'inventory.adjust');

        return $this->idempotent($this->idempotencyKey($data), $actor, function () use ($data, $actor): InventoryMovement {
            [$item, $branch, $location] = $this->guardTarget($data['item_id'], $data['branch_id'], $data['location_id'], $actor);
            if (! in_array($data['direction'] ?? null, ['increase', 'decrease'], true)) {
                throw ValidationException::withMessages(['direction' => 'Choose whether the adjustment increases or decreases stock.']);
            }
            if (blank($data['notes'] ?? null)) {
                throw ValidationException::withMessages(['notes' => 'A reason is required for a stock adjustment.']);
            }
            $quantity = $this->quantity($data['quantity'] ?? null);

            return $this->record($item, $branch, $location, InventoryMovementType::Adjustment, $data['direction'] === 'decrease' ? $quantity->negated() : $quantity, $data, $actor, InventoryMovementStatus::Pending);
        });
    }

    public function approveAdjustment(InventoryMovement $movement, User $actor): InventoryMovement
    {
        $this->authorize($actor, 'inventory.approve_adjustment');

        return DB::transaction(function () use ($movement, $actor): InventoryMovement {
            $movement = $this->lockPendingAdjustment($movement, $actor);
            $balance = $this->lockBalance($movement->item_id, $movement->branch_id, $movement->location_id);
            $this->applyChange($balance, AccountingDecimal::of($movement->quantity));
            $movement->update(['status' => InventoryMovementStatus::Posted, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->log('inventory.adjustment_approved', $movement, [...$this->auditProperties($movement), 'requested_by' => $movement->created_by, 'balance_after' => $balance->quantity]);

            return $movement;
        }, 3);
    }

    public function rejectAdjustment(InventoryMovement $movement, string $reason, User $actor): InventoryMovement
    {
        $this->authorize($actor, 'inventory.approve_adjustment');

        return DB::transaction(function () use ($movement, $reason, $actor): InventoryMovement {
            $movement = $this->lockPendingAdjustment($movement, $actor);
            $movement->update(['status' => InventoryMovementStatus::Rejected, 'approved_by' => $actor->id, 'approved_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->log('inventory.adjustment_rejected', $movement, [...$this->auditProperties($movement), 'requested_by' => $movement->created_by, 'reason' => $reason]);

            return $movement;
        });
    }

    /**
     * Move stock between two branch locations in one transaction. The two
     * legs share a transfer group and always net to zero.
     *
     * @param  array<string, mixed>  $data
     * @return array{out: InventoryMovement, in: InventoryMovement}
     */
    public function transfer(array $data, User $actor): array
    {
        $this->authorize($actor, 'inventory.transfer');

        $key = $this->idempotencyKey($data);
        $legs = $this->idempotent($key === null ? null : $key.':out', $actor, function () use ($data, $actor, $key): array {
            [$item, $fromBranch, $fromLocation] = $this->guardTarget($data['item_id'], $data['from_branch_id'], $data['from_location_id'], $actor, 'from_location_id');
            [, $toBranch, $toLocation] = $this->guardTarget($data['item_id'], $data['to_branch_id'], $data['to_location_id'], $actor, 'to_location_id');
            if ($fromLocation->is($toLocation)) {
                throw ValidationException::withMessages(['to_location_id' => 'Source and destination must be different.']);
            }
            $quantity = $this->quantity($data['quantity'] ?? null);

            // Both balance rows are locked in ascending location order so opposite transfers cannot deadlock.
            $locked = [];
            foreach (collect([[$fromBranch, $fromLocation], [$toBranch, $toLocation]])->sortBy(fn (array $side): int => $side[1]->id) as [$branch, $location]) {
                $locked[$location->id] = $this->lockBalance($item->id, $branch->id, $location->id);
            }
            $source = $locked[$fromLocation->id];
            $destination = $locked[$toLocation->id];

            $this->applyChange($source, $quantity->negated());
            $this->applyChange($destination, $quantity);

            $data['transfer_group'] = (string) Str::uuid();
            $out = $this->record($item, $fromBranch, $fromLocation, InventoryMovementType::TransferOut, $quantity->negated(), [...$data, 'idempotency_key' => $key === null ? null : $key.':out'], $actor, InventoryMovementStatus::Posted, [], $source);
            $in = $this->record($item, $toBranch, $toLocation, InventoryMovementType::TransferIn, $quantity, [...$data, 'idempotency_key' => $key === null ? null : $key.':in'], $actor, InventoryMovementStatus::Posted, [], $destination);

            return ['out' => $out, 'in' => $in];
        });

        // A repeated submission returns the transfer that was already recorded.
        return $legs instanceof InventoryMovement
            ? ['out' => $legs, 'in' => InventoryMovement::query()->where('idempotency_key', $key.':in')->where('created_by', $actor->id)->firstOrFail()]
            : $legs;
    }

    /** Quantity on hand for an item in a branch, optionally at one location. */
    public function quantityOnHand(InventoryItem $item, Branch $branch, ?InventoryLocation $location = null): string
    {
        $rows = InventoryBalance::query()->where('item_id', $item->id)->where('branch_id', $branch->id)
            ->when($location, fn ($query) => $query->where('location_id', $location->id))->get('quantity');

        return AccountingDecimal::string($this->sum($rows->pluck('quantity')));
    }

    /** Stock recomputed from the ledger: the sum of posted movements only. */
    public function postedQuantity(int $itemId, int $branchId, int $locationId): string
    {
        $quantities = InventoryMovement::query()->where('item_id', $itemId)->where('branch_id', $branchId)->where('location_id', $locationId)
            ->where('status', InventoryMovementStatus::Posted->value)->pluck('quantity');

        return AccountingDecimal::string($this->sum($quantities));
    }

    /** Whether the running balance equals the sum of its posted movements. */
    public function balanceIsConsistent(InventoryBalance $balance): bool
    {
        return AccountingDecimal::of($balance->quantity)->isEqualTo(AccountingDecimal::of($this->postedQuantity($balance->item_id, $balance->branch_id, $balance->location_id)));
    }

    /**
     * Every balance row that disagrees with the movement ledger.
     *
     * @return Collection<int, InventoryBalance>
     */
    public function inconsistentBalances(): Collection
    {
        return InventoryBalance::query()->orderBy('id')->get()->reject(fn (InventoryBalance $balance): bool => $this->balanceIsConsistent($balance))->values();
    }

    /**
     * An item is low in a branch where it is stocked when the branch total is
     * at or below a minimum level greater than zero.
     */
    public function isLowStock(InventoryItem $item, Branch $branch): bool
    {
        $minimum = AccountingDecimal::of($item->minimum_stock_level);
        if (! $minimum->isPositive() || ! InventoryBalance::query()->where('item_id', $item->id)->where('branch_id', $branch->id)->exists()) {
            return false;
        }

        return AccountingDecimal::of($this->quantityOnHand($item, $branch))->isLessThanOrEqualTo($minimum);
    }

    /**
     * One row per low item and branch (item_id, branch_id, total_quantity).
     * The comparison runs on the database's exact DECIMAL columns.
     *
     * @param  iterable<int>  $branchIds
     */
    public function lowStockQuery(iterable $branchIds): Builder
    {
        return DB::table('inventory_balances as stock')
            ->join('inventory_items as item', 'item.id', '=', 'stock.item_id')
            ->where('item.is_active', true)
            ->where('item.minimum_stock_level', '>', 0)
            ->whereIn('stock.branch_id', collect($branchIds)->all())
            ->groupBy('stock.item_id', 'stock.branch_id', 'item.minimum_stock_level', 'item.name')
            ->havingRaw('SUM(stock.quantity) <= item.minimum_stock_level')
            ->select('stock.item_id', 'stock.branch_id', DB::raw('SUM(stock.quantity) as total_quantity'));
    }

    public function allowsNegativeStock(): bool
    {
        return (bool) $this->settings->get('inventory.allow_negative_stock', false);
    }

    /** @param array<string, mixed> $data */
    private function idempotencyKey(array $data): ?string
    {
        return filled($data['idempotency_key'] ?? null) ? (string) $data['idempotency_key'] : null;
    }

    private function recorded(?string $key, User $actor): ?InventoryMovement
    {
        return $key === null ? null : InventoryMovement::query()->where('idempotency_key', $key)->where('created_by', $actor->id)->first();
    }

    /**
     * Run a stock movement once per idempotency key. A repeated submission,
     * including one that races the first, returns the recorded movement.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult|InventoryMovement
     */
    private function idempotent(?string $key, User $actor, Closure $work): mixed
    {
        try {
            return DB::transaction(fn () => $this->recorded($key, $actor) ?? $work(), 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($key === null) {
                throw $exception;
            }

            return $this->recorded($key, $actor) ?? throw ValidationException::withMessages(['idempotency_key' => 'This form was already submitted. Reload the page before trying again.']);
        }
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless($actor->is_active && $actor->can($permission), 403);
    }

    /**
     * Resolve and check the item, branch and location of a movement.
     *
     * @return array{0: InventoryItem, 1: Branch, 2: InventoryLocation}
     */
    private function guardTarget(mixed $itemId, mixed $branchId, mixed $locationId, User $actor, string $locationKey = 'location_id'): array
    {
        $branch = Branch::query()->findOrFail($branchId);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);

        $item = InventoryItem::query()->find($itemId);
        if (! $item || ! $item->is_active) {
            throw ValidationException::withMessages(['item_id' => 'Select an active inventory item.']);
        }
        $location = InventoryLocation::query()->find($locationId);
        if (! $location || $location->branch_id !== $branch->id) {
            throw ValidationException::withMessages([$locationKey => 'The location does not belong to the selected branch.']);
        }
        if (! $location->is_active) {
            throw ValidationException::withMessages([$locationKey => 'The selected location is inactive.']);
        }

        return [$item, $branch, $location];
    }

    /** A movement quantity: greater than zero with at most four decimals. */
    private function quantity(mixed $value): BigDecimal
    {
        try {
            $quantity = AccountingDecimal::of(is_string($value) || is_int($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a number with at most 4 decimal places.']);
        }
        if (! $quantity->isPositive() || $quantity->isGreaterThan(self::MAX_QUANTITY)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        return $quantity;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{purchase_order_id?: int, purchase_order_line_id?: int|null}
     */
    private function guardPurchaseOrder(array $data, Branch $branch): array
    {
        if (blank($data['purchase_order_id'] ?? null)) {
            if (filled($data['purchase_order_line_id'] ?? null)) {
                throw ValidationException::withMessages(['purchase_order_line_id' => 'Select the purchase order this line belongs to.']);
            }

            return [];
        }

        $order = PurchaseOrder::query()->find($data['purchase_order_id']);
        if (! $order || ! $order->status->acceptsBills()) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Only an approved or issued purchase order can be referenced.']);
        }
        if ($order->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['purchase_order_id' => 'The purchase order belongs to a different branch.']);
        }
        $lineId = filled($data['purchase_order_line_id'] ?? null) ? (int) $data['purchase_order_line_id'] : null;
        if ($lineId !== null && ! $order->lines()->whereKey($lineId)->exists()) {
            throw ValidationException::withMessages(['purchase_order_line_id' => 'The line does not belong to the selected purchase order.']);
        }

        return ['purchase_order_id' => $order->id, 'purchase_order_line_id' => $lineId];
    }

    /**
     * Lock the balance, apply the signed quantity and append the movement.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     */
    private function post(InventoryItem $item, Branch $branch, InventoryLocation $location, InventoryMovementType $type, BigDecimal $signedQuantity, array $data, User $actor, array $extra = []): InventoryMovement
    {
        $balance = $this->lockBalance($item->id, $branch->id, $location->id);
        $this->applyChange($balance, $signedQuantity);

        return $this->record($item, $branch, $location, $type, $signedQuantity, $data, $actor, InventoryMovementStatus::Posted, $extra, $balance);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     */
    private function record(InventoryItem $item, Branch $branch, InventoryLocation $location, InventoryMovementType $type, BigDecimal $signedQuantity, array $data, User $actor, InventoryMovementStatus $status, array $extra = [], ?InventoryBalance $balance = null): InventoryMovement
    {
        $movement = InventoryMovement::query()->create([
            'item_id' => $item->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
            'type' => $type, 'quantity' => AccountingDecimal::string($signedQuantity), 'transaction_date' => $data['transaction_date'],
            'issued_to' => $data['issued_to'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
            'transfer_group' => $data['transfer_group'] ?? null, 'status' => $status, 'created_by' => $actor->id,
            'idempotency_key' => $this->idempotencyKey($data),
            ...$extra,
        ]);
        $this->audit->log($type->auditEvent(), $movement, [...$this->auditProperties($movement), 'balance_after' => $balance?->quantity]);

        return $movement;
    }

    /** Insert the zero balance row if it is missing and return its id. */
    private function ensureBalance(int $itemId, int $branchId, int $locationId): int
    {
        $key = ['item_id' => $itemId, 'branch_id' => $branchId, 'location_id' => $locationId];
        DB::table('inventory_balances')->insertOrIgnore([...$key, 'quantity' => '0.0000', 'created_at' => now(), 'updated_at' => now()]);

        // A locking read sees a row another transaction has just committed; a plain read may not.
        return (int) DB::table('inventory_balances')->where($key)->lockForUpdate()->value('id');
    }

    private function lockBalance(int $itemId, int $branchId, int $locationId): InventoryBalance
    {
        return InventoryBalance::query()->lockForUpdate()->findOrFail($this->ensureBalance($itemId, $branchId, $locationId));
    }

    /** Apply a signed quantity to a locked balance; a decrease may not go below zero unless the setting allows it. */
    private function applyChange(InventoryBalance $balance, BigDecimal $signedQuantity): void
    {
        $current = AccountingDecimal::of($balance->quantity);
        $next = $current->plus($signedQuantity);
        if ($signedQuantity->isNegative() && $next->isNegative() && ! $this->allowsNegativeStock()) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient stock: '.AccountingDecimal::string($current).' available, '.AccountingDecimal::string($signedQuantity->abs()).' requested.']);
        }
        if ($next->abs()->isGreaterThan(self::MAX_QUANTITY)) {
            throw ValidationException::withMessages(['quantity' => 'The resulting stock balance is too large to store.']);
        }
        $balance->update(['quantity' => AccountingDecimal::string($next)]);
    }

    private function lockPendingAdjustment(InventoryMovement $movement, User $actor): InventoryMovement
    {
        $movement = InventoryMovement::query()->with('branch')->lockForUpdate()->findOrFail($movement->id);
        abort_unless($this->branchAccess->canAccess($actor, $movement->branch), 403);
        if ($movement->type !== InventoryMovementType::Adjustment || $movement->status !== InventoryMovementStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Only a pending adjustment can be approved or rejected.']);
        }
        if ($movement->created_by === $actor->id) {
            throw ValidationException::withMessages(['approval' => 'The user who requested an adjustment cannot approve or reject it.']);
        }

        return $movement;
    }

    /** @return array<string, mixed> */
    private function auditProperties(InventoryMovement $movement): array
    {
        return [
            'item_id' => $movement->item_id, 'branch_id' => $movement->branch_id, 'location_id' => $movement->location_id,
            'type' => $movement->type->value, 'quantity' => $movement->quantity, 'status' => $movement->status->value,
            'issued_to' => $movement->issued_to, 'reference' => $movement->reference, 'transfer_group' => $movement->transfer_group,
            'purchase_order_id' => $movement->purchase_order_id,
        ];
    }

    /** @param Collection<int, mixed> $quantities */
    private function sum(Collection $quantities): BigDecimal
    {
        return $quantities->reduce(fn (BigDecimal $carry, mixed $quantity): BigDecimal => $carry->plus(AccountingDecimal::of($quantity)), BigDecimal::zero()->toScale(4));
    }
}
