<?php

namespace App\Models;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialStatement;
use App\Enums\NormalBalance;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'parent_id', 'account_type', 'level', 'normal_balance', 'financial_statement', 'financial_account_type', 'purchase_bill_debit_eligible', 'direct_payment_eligible', 'is_postable', 'is_active', 'description', 'sort_order', 'created_by'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'level' => AccountLevel::class,
            'normal_balance' => NormalBalance::class,
            'financial_statement' => FinancialStatement::class,
            'financial_account_type' => FinancialAccountType::class,
            'purchase_bill_debit_eligible' => 'boolean',
            'direct_payment_eligible' => 'boolean',
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Whether any account above this one is inactive. Deactivation is child-first,
     * so this only happens in legacy data; posting still treats such a ledger as unusable.
     */
    public function hasInactiveAncestor(): bool
    {
        $seen = [];
        for ($parentId = $this->parent_id; $parentId && ! isset($seen[$parentId]); $parentId = $parent->parent_id) {
            $seen[$parentId] = true;
            $parent = self::query()->find($parentId, ['id', 'parent_id', 'is_active']);
            if (! $parent) {
                return false;
            }
            if (! $parent->is_active) {
                return true;
            }
        }

        return false;
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function openingBalanceLines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class);
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    /**
     * Expense ledgers approved for direct bank payment: explicitly flagged by an
     * authorized user (default deny) and still an active, postable, debit-normal
     * Income & Expenditure expense ledger. Callers also exclude ledgers under an
     * inactive ancestor.
     *
     * @param  Builder<Account>  $query
     */
    public function scopeEligibleForDirectPayment(Builder $query): void
    {
        $query->where('direct_payment_eligible', true)->where('level', AccountLevel::Ledger->value)
            ->where('account_type', AccountType::Expense->value)->where('normal_balance', NormalBalance::Debit->value)
            ->where('financial_statement', FinancialStatement::IncomeExpenditure->value)
            ->where('is_postable', true)->where('is_active', true);
    }

    /** @param Builder<Account> $query */
    public function scopeEligibleForPurchaseBillDebit(Builder $query): void
    {
        $query->where('purchase_bill_debit_eligible', true)
            ->where('level', AccountLevel::Ledger->value)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->whereNull('financial_account_type')
            ->whereDoesntHave('financialAccounts')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('account_type', AccountType::Expense->value)
                        ->where('normal_balance', NormalBalance::Debit->value)
                        ->where('financial_statement', FinancialStatement::IncomeExpenditure->value);
                })->orWhere(function (Builder $query): void {
                    $query->where('account_type', AccountType::Asset->value)
                        ->where('normal_balance', NormalBalance::Debit->value)
                        ->where('financial_statement', FinancialStatement::BalanceSheet->value);
                });
            });
    }
}
