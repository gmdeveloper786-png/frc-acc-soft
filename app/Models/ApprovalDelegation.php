<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-limited, reasoned grant of Director approval authority to one user.
 */
#[Fillable(['authority', 'delegate_id', 'delegated_by', 'branch_id', 'max_amount', 'reason', 'starts_at', 'ends_at', 'revoked_at', 'revoked_by'])]
class ApprovalDelegation extends Model
{
    protected function casts(): array
    {
        return ['max_amount' => 'decimal:4', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @param Builder<ApprovalDelegation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
