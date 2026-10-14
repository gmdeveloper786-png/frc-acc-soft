<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['branch_id', 'accounting_period_id', 'status', 'active_key', 'source', 'gross_amount', 'total_deductions', 'net_amount', 'journal_entry_id', 'created_by', 'validated_by', 'submitted_by', 'approved_by', 'posted_by', 'reversed_by', 'validated_at', 'submitted_at', 'approved_at', 'posted_at', 'paid_at', 'reversed_at', 'rejection_reason', 'reversal_reason'])]
class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    /** Frozen as soon as the run leaves draft. */
    private const LOCKED_FIELDS = ['branch_id', 'accounting_period_id', 'gross_amount', 'total_deductions', 'net_amount', 'source', 'created_by'];

    /** The only fields a posted run may still change: payment status and reversal. */
    private const POSTED_MUTABLE_FIELDS = ['status', 'active_key', 'paid_at', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (PayrollRun $run): void {
            $original = PayrollRunStatus::from((string) $run->getRawOriginal('status'));
            if ($original === PayrollRunStatus::Reversed) {
                throw new LogicException('A reversed payroll run is immutable.');
            }
            if ($original->isPosted() && array_diff(array_keys($run->getDirty()), self::POSTED_MUTABLE_FIELDS) !== []) {
                throw new LogicException('A posted payroll run is immutable and must be corrected by reversal.');
            }
            if (! $original->isEditable() && $run->isDirty(self::LOCKED_FIELDS)) {
                throw new LogicException('Payroll totals cannot change after the run has been validated.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Payroll runs are never deleted; discard or reverse them instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class, 'gross_amount' => 'decimal:4', 'total_deductions' => 'decimal:4', 'net_amount' => 'decimal:4',
            'validated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'paid_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(PayrollRunHistory::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollRunPayment::class)->orderBy('id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
