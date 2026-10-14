<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of one FRC fee payment. Source fields are written only by
 * the sync; `posting_status` and `posting_note` are owned by fee posting.
 */
#[Fillable(['frc_payment_id', 'receipt_number', 'frc_enrollment_id', 'frc_enrollment_mirror_id', 'frc_branch_id', 'frc_service_id', 'branch_id', 'service_id', 'amount', 'payment_method', 'source_status', 'enrollment_status', 'payment_date', 'verified_at', 'source_created_at', 'source_updated_at', 'source_deleted_at', 'content_hash', 'posting_status', 'posting_note', 'last_sync_run_id'])]
class FrcPayment extends Model
{
    /** FRC methods that the Finance Report counts as cash; every other method is online/bank there. */
    public const CASH_METHODS = ['cash'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'payment_date' => 'date', 'verified_at' => 'datetime', 'source_created_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(FrcEnrollment::class, 'frc_enrollment_mirror_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }

    public function postings(): HasMany
    {
        return $this->hasMany(FrcFeePosting::class);
    }
}
