<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialStatement;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Account
    {
        $this->validateHierarchy($data);
        $this->validatePurchaseBillDebitEligibility($data);

        return DB::transaction(function () use ($data, $actor): Account {
            $account = Account::query()->create([...$data, 'created_by' => $actor->id]);
            $this->audit->log('account.created', $account, ['code' => $account->code, 'name' => $account->name]);

            return $account;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Account $account, array $data): Account
    {
        $this->validateHierarchy($data, $account);
        $this->validatePurchaseBillDebitEligibility($data, $account);
        $this->guardReferencedClassification($account, $data);

        return DB::transaction(function () use ($account, $data): Account {
            $before = $account->only(['code', 'name', 'parent_id', 'account_type', 'level', 'normal_balance', 'financial_statement', 'financial_account_type', 'purchase_bill_debit_eligible', 'is_postable', 'is_active']);
            $account->update($data);
            $this->audit->log('account.updated', $account, ['before' => $before, 'after' => $account->fresh()->only(array_keys($before))]);

            return $account->refresh();
        });
    }

    public function setActive(Account $account, bool $active): void
    {
        if ($active) {
            $this->validatePurchaseBillDebitEligibility(['is_active' => true], $account);
        }
        $account->update(['is_active' => $active]);
        $this->audit->log($active ? 'account.activated' : 'account.deactivated', $account, ['code' => $account->code]);
    }

    /** @param array<string, mixed> $data */
    private function validateHierarchy(array $data, ?Account $account = null): void
    {
        $level = AccountLevel::from((string) $data['level']);
        $type = AccountType::from((string) $data['account_type']);
        $parentId = $data['parent_id'] ?? null;

        if ($level === AccountLevel::ClassLevel && $parentId) {
            throw ValidationException::withMessages(['parent_id' => 'A class account cannot have a parent.']);
        }
        if ($level !== AccountLevel::ClassLevel && ! $parentId) {
            throw ValidationException::withMessages(['parent_id' => 'This account level requires a parent account.']);
        }
        if (($data['is_postable'] ?? false) && $level !== AccountLevel::Ledger) {
            throw ValidationException::withMessages(['is_postable' => 'Only ledger accounts may allow posting.']);
        }
        if (filled($data['financial_account_type'] ?? null)
            && ($level !== AccountLevel::Ledger
                || $type !== AccountType::Asset
                || NormalBalance::from((string) $data['normal_balance']) !== NormalBalance::Debit
                || FinancialStatement::from((string) $data['financial_statement']) !== FinancialStatement::BalanceSheet
                || ! ($data['is_postable'] ?? false))) {
            throw ValidationException::withMessages([
                'financial_account_type' => 'Cash and bank eligibility is only valid for posting-enabled Asset ledgers with a debit normal balance on the Balance Sheet.',
            ]);
        }
        if ($account && (int) $parentId === $account->id) {
            throw ValidationException::withMessages(['parent_id' => 'An account cannot be its own parent.']);
        }

        if ($parentId) {
            $parent = Account::query()->findOrFail($parentId);
            if ($parent->level !== $level->parentLevel()) {
                throw ValidationException::withMessages(['parent_id' => 'The selected parent is not at the required hierarchy level.']);
            }
            if ($parent->account_type !== $type || $parent->financial_statement->value !== (string) $data['financial_statement']) {
                throw ValidationException::withMessages(['parent_id' => 'Parent classification and financial statement must match the child account.']);
            }
            if ($account && $this->isDescendant($parent, $account)) {
                throw ValidationException::withMessages(['parent_id' => 'Circular account hierarchies are not allowed.']);
            }
        }
    }

    private function isDescendant(Account $candidate, Account $account): bool
    {
        $seen = [];
        while ($candidate->parent_id) {
            if ($candidate->parent_id === $account->id) {
                return true;
            }
            if (isset($seen[$candidate->parent_id])) {
                return true;
            }
            $seen[$candidate->parent_id] = true;
            $candidate = Account::query()->find($candidate->parent_id);
            if (! $candidate) {
                break;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function guardReferencedClassification(Account $account, array $data): void
    {
        $financialTypeChanged = (string) $account->getRawOriginal('financial_account_type') !== (string) ($data['financial_account_type'] ?? null);
        if ($financialTypeChanged && $account->financialAccounts()->exists()) {
            throw ValidationException::withMessages([
                'financial_account_type' => 'A ledger linked to a cash or bank account cannot have its financial account eligibility changed.',
            ]);
        }

        $protected = ['code', 'parent_id', 'account_type', 'level', 'normal_balance', 'financial_statement', 'financial_account_type', 'is_postable'];
        $changed = collect($protected)->contains(fn (string $field): bool => (string) $account->getRawOriginal($field) !== (string) ($data[$field] ?? null));
        if (! $changed) {
            return;
        }

        $referenced = $account->openingBalanceLines()->exists();
        if (! $referenced && Schema::hasTable('journal_lines')) {
            $referenced = DB::table('journal_lines')->where('account_id', $account->id)->exists();
        }
        if ($referenced) {
            throw ValidationException::withMessages(['account' => 'Referenced accounts cannot have their accounting classification or hierarchy changed.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function validatePurchaseBillDebitEligibility(array $data, ?Account $account = null): void
    {
        $eligible = (bool) ($data['purchase_bill_debit_eligible'] ?? $account?->purchase_bill_debit_eligible ?? false);
        if (! $eligible) {
            return;
        }

        $attributes = [
            'account_type' => $data['account_type'] ?? $account?->account_type?->value,
            'level' => $data['level'] ?? $account?->level?->value,
            'normal_balance' => $data['normal_balance'] ?? $account?->normal_balance?->value,
            'financial_statement' => $data['financial_statement'] ?? $account?->financial_statement?->value,
            'financial_account_type' => $data['financial_account_type'] ?? $account?->financial_account_type?->value,
            'is_postable' => $data['is_postable'] ?? $account?->is_postable,
            'is_active' => $data['is_active'] ?? $account?->is_active,
        ];
        $isExpense = $attributes['account_type'] === AccountType::Expense->value
            && $attributes['financial_statement'] === FinancialStatement::IncomeExpenditure->value;
        $isAsset = $attributes['account_type'] === AccountType::Asset->value
            && $attributes['financial_statement'] === FinancialStatement::BalanceSheet->value;
        if ($attributes['level'] !== AccountLevel::Ledger->value
            || $attributes['normal_balance'] !== NormalBalance::Debit->value
            || ! ($attributes['is_postable'] ?? false)
            || ! ($attributes['is_active'] ?? false)
            || filled($attributes['financial_account_type'])
            || (! $isExpense && ! $isAsset)
            || ($account && $account->financialAccounts()->exists())) {
            throw ValidationException::withMessages([
                'purchase_bill_debit_eligible' => 'Purchase Bill debit eligibility sirf active, postable debit-normal Expense ya Asset ledger ke liye hai. Cash aur bank ledgers eligible nahi ho sakte.',
            ]);
        }
    }
}
