<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only workflow history for a purchase order.
 */
#[Fillable(['purchase_order_id', 'action', 'from_status', 'to_status', 'approval_tier', 'amount', 'actor_id', 'comments', 'created_at'])]
class PurchaseOrderApproval extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $immutable = function (): void {
            throw new LogicException('Purchase order history cannot be changed or deleted.');
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'created_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
