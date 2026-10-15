<?php

namespace App\Models;

use Database\Factories\BankStatementLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['bank_statement_id', 'financial_account_id', 'row_number', 'transaction_date', 'description', 'reference', 'amount', 'running_balance', 'status', 'ignored_in_reconciliation_id'])]
class BankStatementLine extends Model
{
    /** @use HasFactory<BankStatementLineFactory> */
    use HasFactory;

    public const UNMATCHED = 'unmatched';

    public const MATCHED = 'matched';

    public const IGNORED = 'ignored';

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount' => 'decimal:4', 'running_balance' => 'decimal:4'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function match(): HasOne
    {
        return $this->hasOne(BankReconciliationMatch::class);
    }
}
