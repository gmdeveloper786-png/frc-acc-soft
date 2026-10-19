<?php

namespace App\Models;

use App\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['voucher_type_id', 'transaction_date', 'fiscal_year_id', 'accounting_period_id', 'branch_id', 'narration', 'reference_number', 'source_system', 'source_type', 'source_id', 'source_key', 'status', 'total_debit', 'total_credit', 'created_by', 'submitted_by', 'approved_by', 'posted_by', 'reversed_by', 'submitted_at', 'approved_at', 'posted_at', 'reversed_at', 'rejection_reason', 'reversal_reason', 'reversal_of_id'])]
class JournalEntry extends Model
{
    protected static function booted(): void
    {
        static::updating(function (JournalEntry $journal): void {
            $originalStatus = $journal->getRawOriginal('status');

            if ($originalStatus === JournalStatus::Posted->value) {
                $allowedReversalFields = ['status', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_at'];
                $isReversalTransition = $journal->status === JournalStatus::Reversed
                    && empty(array_diff(array_keys($journal->getDirty()), $allowedReversalFields));

                if (! $isReversalTransition) {
                    throw new \LogicException('A posted journal is immutable and must be corrected by reversal.');
                }
            }

            if ($originalStatus === JournalStatus::Reversed->value) {
                throw new \LogicException('A reversed journal is immutable.');
            }
        });

        static::deleting(function (JournalEntry $journal): void {
            if (in_array($journal->status, [JournalStatus::Posted, JournalStatus::Reversed], true)) {
                throw new \LogicException('Posted or reversed journals cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'status' => JournalStatus::class,
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
    }

    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * Short label for lists: the voucher number once posted (kept after a
     * reversal), otherwise the status and id. A voucher number exists only
     * after posting, so none is ever shown for an unposted journal.
     */
    public function displayLabel(): string
    {
        return $this->voucher_number ?: $this->status->label().' #'.$this->id;
    }

    /** Page heading: the voucher number once posted, otherwise e.g. "Submitted Journal #2". */
    public function displayTitle(): string
    {
        return $this->voucher_number ?: $this->status->label().' Journal #'.$this->id;
    }
}
