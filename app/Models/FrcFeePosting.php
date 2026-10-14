<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link between an FRC payment or enrollment and the journal that recognised it. */
#[Fillable(['kind', 'basis', 'frc_payment_id', 'frc_enrollment_id', 'amount', 'source_key', 'journal_entry_id', 'reversal_journal_entry_id', 'status', 'posted_by', 'reversed_by', 'reversal_reason', 'reversed_at'])]
class FrcFeePosting extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'reversed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FrcPayment::class, 'frc_payment_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(FrcEnrollment::class, 'frc_enrollment_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }
}
