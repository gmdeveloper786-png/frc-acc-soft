<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Read-only mirror of an FRC enrollment (the fee agreement). Never edited locally. */
#[Fillable(['frc_enrollment_id', 'frc_branch_id', 'frc_service_id', 'branch_id', 'service_id', 'source_status', 'payment_status', 'final_total', 'discount_amount', 'paid_amount', 'remaining_amount', 'source_approved_at', 'source_created_at', 'source_updated_at', 'source_deleted_at', 'content_hash', 'last_sync_run_id'])]
class FrcEnrollment extends Model
{
    protected function casts(): array
    {
        return ['final_total' => 'decimal:4', 'discount_amount' => 'decimal:4', 'paid_amount' => 'decimal:4', 'remaining_amount' => 'decimal:4', 'source_approved_at' => 'datetime', 'source_created_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FrcPayment::class, 'frc_enrollment_mirror_id');
    }
}
