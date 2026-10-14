<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only workflow history for a budget.
 */
#[Fillable(['budget_id', 'action', 'from_status', 'to_status', 'total_amount', 'actor_id', 'comments', 'created_at'])]
class BudgetApproval extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $immutable = function (): void {
            throw new LogicException('Budget history cannot be changed or deleted.');
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:4', 'created_at' => 'datetime'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
