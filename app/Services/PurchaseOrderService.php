<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FrcService;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders are procurement commitments. Nothing in this service posts
 * to the General Ledger; payables are recognised only by purchase bills.
 */
class PurchaseOrderService
{
    public const TIER_STANDARD = 'standard';

    public const TIER_DIRECTOR = 'director';

    public function __construct(
        private BranchAccess $branchAccess,
        private DocumentNumberService $numbers,
        private AppSettings $settings,
        private AuditLogger $audit,
        private ApprovalAuthorityService $authority,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.create'), 403);

        return DB::transaction(function () use ($data, $actor): PurchaseOrder {
            $branch = $this->guardHeader($data, $actor);
            $computed = $this->computeLines($data['lines']);

            $order = PurchaseOrder::query()->create([
                ...$this->headerAttributes($data),
                ...$computed['totals'],
                'po_number' => $this->numbers->nextPurchaseOrderNumber(Carbon::parse($data['po_date'])),
                'branch_id' => $branch->id,
                'currency' => $this->settings->string('currency.code', (string) config('accounting.currency.code')),
                'status' => PurchaseOrderStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $order->lines()->createMany($computed['lines']);
            $this->record($order, 'created', null, $actor);
            $this->audit->log('purchase_orders.created', $order, ['po_number' => $order->po_number, 'branch_id' => $branch->id, 'vendor_id' => $order->vendor_id, 'total_amount' => $order->total_amount]);

            return $order->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(PurchaseOrder $order, array $data, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.update'), 403);

        return DB::transaction(function () use ($order, $data, $actor): PurchaseOrder {
            $order = $this->lock($order, $actor);
            if (! $order->status->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft or returned purchase order can be edited.']);
            }
            $branch = $this->guardHeader($data, $actor);
            $computed = $this->computeLines($data['lines']);
            $from = $order->status;
            $previousTotal = $order->total_amount;

            $order->lines()->get()->each->delete();
            $order->update([...$this->headerAttributes($data), ...$computed['totals'], 'branch_id' => $branch->id, 'status' => PurchaseOrderStatus::Draft, 'rejection_reason' => null]);
            $order->lines()->createMany($computed['lines']);
            $this->record($order, 'updated', $from, $actor, "Total changed from {$previousTotal} to {$order->total_amount}.");
            $this->audit->log('purchase_orders.updated', $order, ['po_number' => $order->po_number, 'previous_total' => $previousTotal, 'total_amount' => $order->total_amount], $actor);

            return $order->load('lines');
        });
    }

    public function submit(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.submit'), 403);

        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            $order = $this->lock($order, $actor);
            if (! $order->status->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft or returned purchase order can be submitted.']);
            }
            $this->assertIntegrity($order);
            $from = $order->status;
            $order->update(['status' => PurchaseOrderStatus::Submitted, 'approval_tier' => $this->approvalTier($order->total_amount), 'submitted_by' => $actor->id, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->record($order, 'submitted', $from, $actor);
            $this->audit->log('purchase_orders.submitted', $order, ['po_number' => $order->po_number, 'approval_tier' => $order->approval_tier]);

            return $order;
        });
    }

    public function approve(PurchaseOrder $order, User $actor, ?string $comments = null): PurchaseOrder
    {
        if ($this->approvalBasis($order, $actor) === null) {
            $this->audit->log('purchase_orders.approval_denied', $order, ['po_number' => $order->po_number, 'approval_tier' => $this->approvalTier($order->total_amount), 'total_amount' => $order->total_amount]);
            abort(403, 'You do not hold the approval authority this purchase order requires.');
        }

        return DB::transaction(function () use ($order, $actor, $comments): PurchaseOrder {
            $order = $this->lock($order, $actor);
            $basis = $this->approvalBasis($order, $actor);
            abort_if($basis === null, 403);
            if ($order->status !== PurchaseOrderStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted purchase order can be approved.']);
            }
            $this->assertNotMaker($order, $actor);
            $this->assertIntegrity($order);
            $order->update(['status' => PurchaseOrderStatus::Approved, 'approval_tier' => $this->approvalTier($order->total_amount), 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->record($order, 'approved', PurchaseOrderStatus::Submitted, $actor, $basis === 'standard' || $basis === 'director_role' ? $comments : trim('[Delegated Director authority '.$basis.'] '.$comments));
            $this->audit->log('purchase_orders.approved', $order, ['po_number' => $order->po_number, 'approval_tier' => $order->approval_tier, 'total_amount' => $order->total_amount, 'authority_basis' => $basis]);

            return $order;
        });
    }

    /**
     * The authority under which the user may approve this order, or null.
     * Above the threshold (or under the "director_only" policy) a permission is
     * not enough: the user must hold Director authority by role or delegation.
     */
    public function approvalBasis(PurchaseOrder $order, User $actor): ?string
    {
        if (! $actor->is_active || ! $this->branchAccess->canAccess($actor, $order->branch)) {
            return null;
        }
        if ($this->approvalPermission($order->total_amount) === 'purchase_orders.approve_high_value') {
            return $this->authority->directorBasis($actor, $order->branch, $order->total_amount);
        }

        return $actor->can('purchase_orders.approve') ? 'standard' : null;
    }

    /** Whether the user may approve this submitted order right now. */
    public function canApprove(PurchaseOrder $order, User $actor): bool
    {
        return $order->status === PurchaseOrderStatus::Submitted
            && ! in_array($actor->id, [$order->created_by, $order->submitted_by, ...$this->editors($order)], true)
            && $this->approvalBasis($order, $actor) !== null;
    }

    public function reject(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.reject'), 403);

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = $this->lock($order, $actor);
            if ($order->status !== PurchaseOrderStatus::Submitted) {
                throw ValidationException::withMessages(['status' => 'Only a submitted purchase order can be rejected.']);
            }
            $this->assertNotMaker($order, $actor);
            $order->update(['status' => PurchaseOrderStatus::Rejected, 'rejection_reason' => $reason]);
            $this->record($order, 'rejected', PurchaseOrderStatus::Submitted, $actor, $reason);
            $this->audit->log('purchase_orders.rejected', $order, ['po_number' => $order->po_number, 'reason' => $reason]);

            return $order;
        });
    }

    public function issue(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.issue'), 403);

        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            $order = $this->lock($order, $actor);
            if ($order->status !== PurchaseOrderStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Only an approved purchase order can be issued to the vendor.']);
            }
            $order->update(['status' => PurchaseOrderStatus::Issued, 'issued_by' => $actor->id, 'issued_at' => now()]);
            $this->record($order, 'issued', PurchaseOrderStatus::Approved, $actor);
            $this->audit->log('purchase_orders.issued', $order, ['po_number' => $order->po_number]);

            return $order;
        });
    }

    public function close(PurchaseOrder $order, ?string $reason, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.close'), 403);

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = $this->lock($order, $actor);
            if (! $order->status->acceptsBills()) {
                throw ValidationException::withMessages(['status' => 'Only an approved or issued purchase order can be closed.']);
            }
            $from = $order->status;
            $order->update(['status' => PurchaseOrderStatus::Closed, 'closed_by' => $actor->id, 'closed_at' => now(), 'closure_reason' => $reason]);
            $this->record($order, 'closed', $from, $actor, $reason);
            $this->audit->log('purchase_orders.closed', $order, ['po_number' => $order->po_number, 'billed_amount' => $order->billedAmount()]);

            return $order;
        });
    }

    public function cancel(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        abort_unless($actor->can('purchase_orders.cancel'), 403);

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = $this->lock($order, $actor);
            if (in_array($order->status, [PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => 'A closed or cancelled purchase order cannot be cancelled.']);
            }
            if ($order->activeBills()->exists()) {
                throw ValidationException::withMessages(['status' => 'This purchase order has linked purchase bills. Reverse or reject them first, or close the order instead.']);
            }
            $from = $order->status;
            $order->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'closure_reason' => $reason]);
            $this->record($order, 'cancelled', $from, $actor, $reason);
            $this->audit->log('purchase_orders.cancelled', $order, ['po_number' => $order->po_number, 'reason' => $reason]);

            return $order;
        });
    }

    /** Orders above the configured threshold need Director approval. */
    public function approvalTier(mixed $total): string
    {
        $threshold = AccountingDecimal::of($this->settings->string('approval.purchase_order_threshold', (string) config('accounting.approvals.purchase_order_threshold')));

        return AccountingDecimal::of($total)->isGreaterThan($threshold) ? self::TIER_DIRECTOR : self::TIER_STANDARD;
    }

    /**
     * Permission required to approve an order of this value. At or below the
     * threshold the configured policy decides; nothing is ever auto-approved.
     */
    public function approvalPermission(mixed $total): string
    {
        if ($this->approvalTier($total) === self::TIER_DIRECTOR) {
            return 'purchase_orders.approve_high_value';
        }

        return $this->settings->string('approval.purchase_order_standard_policy', 'authorized_approver') === 'director_only'
            ? 'purchase_orders.approve_high_value'
            : 'purchase_orders.approve';
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>}
     */
    private function computeLines(array $lines): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        [$subtotal, $discountTotal, $taxTotal, $total] = [$zero, $zero, $zero, $zero];
        $rows = [];

        foreach (array_values($lines) as $index => $line) {
            $gross = AccountingDecimal::of($line['quantity'])->multipliedBy(AccountingDecimal::of($line['unit_price']))->toScale(4, RoundingMode::HalfUp);
            $discount = AccountingDecimal::of($line['discount_amount'] ?? '0');
            $tax = AccountingDecimal::of($line['tax_amount'] ?? '0');
            $lineTotal = $gross->minus($discount)->plus($tax);
            if ($discount->isNegative() || $tax->isNegative() || $discount->isGreaterThan($gross) || ! $lineTotal->isPositive()) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Each line needs a positive total; discount cannot exceed the line value.']);
            }
            if (filled($line['account_id'] ?? null) && ! Account::query()->whereKey($line['account_id'])->whereIn('account_type', [AccountType::Expense->value, AccountType::Asset->value])->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Select an active postable expense or asset ledger.']);
            }
            if (filled($line['service_id'] ?? null) && ! FrcService::query()->whereKey($line['service_id'])->whereIn('status', ['publish', 'active'])->exists()) {
                throw ValidationException::withMessages(["lines.{$index}.service_id" => 'The selected service is not available.']);
            }

            $rows[] = [
                'line_number' => $index + 1, 'description' => $line['description'], 'unit' => $line['unit'] ?? null,
                'quantity' => AccountingDecimal::string(AccountingDecimal::of($line['quantity'])), 'unit_price' => AccountingDecimal::string(AccountingDecimal::of($line['unit_price'])),
                'discount_amount' => AccountingDecimal::string($discount), 'tax_amount' => AccountingDecimal::string($tax), 'line_total' => AccountingDecimal::string($lineTotal),
                'account_id' => $line['account_id'] ?? null, 'service_id' => $line['service_id'] ?? null,
            ];
            $subtotal = $subtotal->plus($gross);
            $discountTotal = $discountTotal->plus($discount);
            $taxTotal = $taxTotal->plus($tax);
            $total = $total->plus($lineTotal);
        }

        return ['lines' => $rows, 'totals' => [
            'subtotal' => AccountingDecimal::string($subtotal), 'discount_total' => AccountingDecimal::string($discountTotal),
            'tax_total' => AccountingDecimal::string($taxTotal), 'total_amount' => AccountingDecimal::string($total),
        ]];
    }

    /** @param array<string, mixed> $data */
    private function guardHeader(array $data, User $actor): Branch
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        if (! Vendor::query()->whereKey($data['vendor_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'Select an active vendor.']);
        }
        if (empty($data['lines'])) {
            throw ValidationException::withMessages(['lines' => 'A purchase order requires at least one line.']);
        }

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data): array
    {
        return [
            'vendor_id' => $data['vendor_id'], 'po_date' => $data['po_date'],
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'reference' => $data['reference'] ?? null, 'description' => $data['description'] ?? null,
        ];
    }

    private function lock(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $order = PurchaseOrder::query()->with('branch')->lockForUpdate()->findOrFail($order->id);
        abort_unless($this->branchAccess->canAccess($actor, $order->branch), 403);

        return $order;
    }

    private function assertNotMaker(PurchaseOrder $order, User $actor): void
    {
        if (in_array($actor->id, [$order->created_by, $order->submitted_by], true)) {
            throw ValidationException::withMessages(['approval' => 'The user who created or submitted a purchase order cannot approve or reject it.']);
        }
        if (in_array($actor->id, $this->editors($order), true)) {
            throw ValidationException::withMessages(['approval' => 'You edited this purchase order, so another user must approve it.']);
        }
    }

    /** @return list<int> Users who changed the order while it was a draft. */
    private function editors(PurchaseOrder $order): array
    {
        return $this->audit->actorIds($order, ['purchase_orders.updated']);
    }

    /** Header totals must still equal the stored lines and the vendor must be usable. */
    private function assertIntegrity(PurchaseOrder $order): void
    {
        $lines = $order->lines()->get();
        $sum = $lines->reduce(fn (BigDecimal $carry, $line): BigDecimal => $carry->plus(AccountingDecimal::of($line->line_total)), BigDecimal::zero()->toScale(4));
        if ($lines->isEmpty() || ! $sum->isPositive() || ! $sum->isEqualTo(AccountingDecimal::of($order->total_amount))) {
            throw ValidationException::withMessages(['status' => 'Purchase order totals do not match its lines. Edit and save the order again.']);
        }
        if (! $order->vendor()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'The vendor of this purchase order is inactive.']);
        }
    }

    private function record(PurchaseOrder $order, string $action, ?PurchaseOrderStatus $from, User $actor, ?string $comments = null): void
    {
        $order->approvals()->create([
            'action' => $action, 'from_status' => $from?->value, 'to_status' => $order->status->value,
            'approval_tier' => $order->approval_tier, 'amount' => $order->total_amount,
            'actor_id' => $actor->id, 'comments' => $comments, 'created_at' => now(),
        ]);
    }
}
