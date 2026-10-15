<?php

namespace App\Models;

use App\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_entry_id', 'account_id', 'line_number', 'description', 'debit', 'credit', 'service_id', 'fund_id'])]
class JournalLine extends Model
{
    protected static function booted(): void
    {
        $guardPostedJournal = function (JournalLine $line): void {
            $status = $line->journalEntry()->value('status');
            $status = $status instanceof JournalStatus ? $status->value : $status;

            if (in_array($status, [JournalStatus::Posted->value, JournalStatus::Reversed->value], true)) {
                throw new \LogicException('Lines belonging to a posted or reversed journal are immutable.');
            }
        };

        static::creating($guardPostedJournal);
        static::updating($guardPostedJournal);
        static::deleting($guardPostedJournal);
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }

    /** Optional fund. System journals (fee posting, payroll, depreciation, year-end) leave it null. */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
