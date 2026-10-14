<?php

namespace App\Models;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only stock ledger. Quantity is signed: positive increases stock,
 * negative decreases it. The only permitted change is the review decision
 * (approve or reject) of an adjustment that is still pending.
 */
#[Fillable(['item_id', 'branch_id', 'location_id', 'type', 'quantity', 'transaction_date', 'issued_to', 'reference', 'notes', 'purchase_order_id', 'purchase_order_line_id', 'transfer_group', 'idempotency_key', 'status', 'created_by', 'approved_by', 'approved_at', 'rejection_reason'])]
class InventoryMovement extends Model
{
    /** Columns a reviewer may set when deciding a pending adjustment. */
    private const REVIEW_FIELDS = ['status', 'approved_by', 'approved_at', 'rejection_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (InventoryMovement $movement): void {
            $wasPending = $movement->getRawOriginal('status') === InventoryMovementStatus::Pending->value;
            if (! $wasPending || array_diff(array_keys($movement->getDirty()), self::REVIEW_FIELDS) !== []) {
                throw new LogicException('Stock movements are immutable. Record a correcting movement instead.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Stock movements cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class, 'status' => InventoryMovementStatus::class,
            'quantity' => 'decimal:4', 'transaction_date' => 'date', 'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The user who approved or rejected a pending adjustment. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
