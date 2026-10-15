<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to restore an imprest float. It owns a draft CV journal that goes
 * through the normal maker-checker workflow; its status mirrors that journal.
 */
#[Fillable(['imprest_float_id', 'journal_entry_id', 'amount', 'due_at_request', 'covered_voucher_ids', 'covered_from', 'covered_to', 'status', 'created_by'])]
class ImprestReplenishment extends Model
{
    /** Statuses of a replenishment whose journal is not yet posted and still counts as open. */
    public const OPEN_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'due_at_request' => 'decimal:4', 'covered_voucher_ids' => 'array', 'covered_from' => 'date', 'covered_to' => 'date'];
    }

    public function imprestFloat(): BelongsTo
    {
        return $this->belongsTo(ImprestFloat::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
