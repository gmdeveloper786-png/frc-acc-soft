<?php

namespace App\Models;

use App\Enums\DepreciationConvention;
use App\Enums\DepreciationRunStatus;
use Database\Factories\DepreciationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['branch_id', 'accounting_period_id', 'status', 'convention', 'total_amount', 'journal_entry_id', 'created_by', 'submitted_by', 'approved_by', 'posted_by', 'reversed_by', 'submitted_at', 'approved_at', 'posted_at', 'reversed_at', 'rejection_reason', 'reversal_reason'])]
class DepreciationRun extends Model
{
    /** @use HasFactory<DepreciationRunFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (DepreciationRun $run): void {
            $original = DepreciationRunStatus::from((string) $run->getRawOriginal('status'));
            if ($original === DepreciationRunStatus::Reversed) {
                throw new LogicException('A reversed depreciation run is immutable.');
            }
            if ($original === DepreciationRunStatus::Posted) {
                $allowed = ['status', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_at'];
                if ($run->status !== DepreciationRunStatus::Reversed || array_diff(array_keys($run->getDirty()), $allowed) !== []) {
                    throw new LogicException('A posted depreciation run is immutable and must be corrected by reversal.');
                }
            }
        });
        static::deleting(function (DepreciationRun $run): void {
            if ($run->status !== DepreciationRunStatus::Draft) {
                throw new LogicException('Only a draft depreciation run can be discarded.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DepreciationRunStatus::class, 'convention' => DepreciationConvention::class, 'total_amount' => 'decimal:4',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationRunLine::class)->orderBy('id');
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
}
