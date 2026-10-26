<?php

namespace App\Models;

use Database\Factories\BankReconciliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['financial_account_id', 'branch_id', 'bank_statement_id', 'statement_date', 'statement_closing_balance', 'status', 'open_key', 'summary', 'prepared_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason'])]
class BankReconciliation extends Model
{
    /** @use HasFactory<BankReconciliationFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    /** An abandoned draft: its matches were released and the account is free for a new reconciliation. */
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::SUBMITTED, self::APPROVED, self::CANCELLED];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_closing_balance' => 'decimal:4',
            'summary' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }

    public function badge(): string
    {
        return match ($this->status) {
            self::APPROVED => 'success',
            self::SUBMITTED => 'pending',
            self::CANCELLED => 'danger',
            default => 'neutral',
        };
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
