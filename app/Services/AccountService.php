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

    /** Extra code digits each level adds to its parent's code (1 → 11 → 111 → 11101). */
    private const CODE_WIDTH = ['group' => 1, 'sub_group' => 1, 'ledger' => 2];

    /**
     * Create a Group, Sub Group or Ledger under an existing parent from the Add
     * Account screen. The parent decides the type and statement; the type decides
     * the normal balance (a ledger may be marked contra); the server assigns the
     * code inside the transaction while the parent row is locked. The five
     * classes are fixed and cannot be created here.
     *
     * @param  array{level: string, parent_id: int|string, name: string, account_type?: ?string, description?: ?string, is_contra?: mixed, is_postable?: mixed, financial_account_type?: ?string, purchase_bill_debit_eligible?: mixed}  $data
     */
    public function createChild(array $data, User $actor): Account
    {
        $level = AccountLevel::tryFrom((string) ($data['level'] ?? ''));
        if (! $level || $level === AccountLevel::ClassLevel) {
            throw ValidationException::withMessages(['level' => 'The five account classes are fixed. Add a Group, Sub Group or Ledger under an existing parent.']);
        }

        return DB::transaction(function () use ($data, $actor, $level): Account {
            $parent = Account::query()->lockForUpdate()->find($data['parent_id'] ?? null);
            if (! $parent || ! $parent->is_active || $parent->level !== $level->parentLevel()) {
                throw ValidationException::withMessages(['parent_id' => 'Select an active parent at the level directly above the new account.']);
            }
            if ($inactive = $this->inactiveAncestorCodes($parent)) {
                throw ValidationException::withMessages(['parent_id' => 'Accounts cannot be added under an inactive account ('.implode(', ', $inactive).'). Activate it first.']);
            }
            if (filled($data['account_type'] ?? null) && $data['account_type'] !== $parent->account_type->value) {
                throw ValidationException::withMessages(['parent_id' => 'The parent belongs to a different account type.']);
            }

            $isLedger = $level === AccountLevel::Ledger;
            $contra = $isLedger && filter_var($data['is_contra'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $debitType = in_array($parent->account_type, [AccountType::Asset, AccountType::Expense], true);
            $siblingSort = (int) Account::query()->where('parent_id', $parent->id)->max('sort_order');
            $attributes = [
                'code' => $this->nextCode($parent, $level),
                'name' => trim((string) $data['name']),
                'parent_id' => $parent->id,
                'account_type' => $parent->account_type->value,
                'level' => $level->value,
                'normal_balance' => ($debitType xor $contra) ? NormalBalance::Debit->value : NormalBalance::Credit->value,
                'financial_statement' => $parent->financial_statement->value,
                'financial_account_type' => $isLedger && filled($data['financial_account_type'] ?? null) ? (string) $data['financial_account_type'] : null,
                'purchase_bill_debit_eligible' => $isLedger && filter_var($data['purchase_bill_debit_eligible'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'is_postable' => $isLedger && filter_var($data['is_postable'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'is_active' => true,
                'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null,
                'sort_order' => max($siblingSort, (int) $parent->sort_order) + 1,
            ];
            $this->validateHierarchy($attributes);
            $this->validatePurchaseBillDebitEligibility($attributes);

            $account = Account::query()->create([...$attributes, 'created_by' => $actor->id]);
            $this->audit->log('account.created', $account, ['code' => $account->code, 'name' => $account->name, 'code_generated' => true, 'parent_code' => $parent->code]);

            return $account;
        }, 3);
    }

    /**
     * The code the next child of $parent would get, for the form preview only;
     * createChild() assigns the real code. Null when it cannot be generated.
     */
    public function previewNextCode(Account $parent): ?string
    {
        $level = collect(AccountLevel::cases())->first(fn (AccountLevel $candidate): bool => $candidate->parentLevel() === $parent->level);
        try {
            return $level ? $this->nextCode($parent, $level) : null;
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Next free code under $parent: the parent code plus the next suffix after
     * the highest one used (gaps are never reused, so historical or excluded
     * codes stay reserved). Refuses when the existing children do not follow
     * the numbering, or the level's range is exhausted.
     */
    private function nextCode(Account $parent, AccountLevel $level): string
    {
        $width = self::CODE_WIDTH[$level->value];
        if (! ctype_digit($parent->code)) {
            throw ValidationException::withMessages(['parent_id' => "A code cannot be generated under {$parent->code}: it is not numeric."]);
        }
        $nonConforming = Account::query()->where('parent_id', $parent->id)->pluck('code')
            ->reject(fn (string $code): bool => ctype_digit($code) && str_starts_with($code, $parent->code) && strlen($code) === strlen($parent->code) + $width);
        if ($nonConforming->isNotEmpty()) {
            throw ValidationException::withMessages(['parent_id' => "A code cannot be generated under {$parent->code}: existing child codes ({$nonConforming->implode(', ')}) do not follow the numbering."]);
        }

        // Every account with this prefix and length counts, whatever its parent, so no code is ever reused.
        $highest = Account::query()->where('code', 'like', $parent->code.str_repeat('_', $width))->pluck('code')
            ->filter(fn (string $code): bool => ctype_digit($code) && strlen($code) === strlen($parent->code) + $width)
            ->map(fn (string $code): int => (int) substr($code, -$width))->max() ?? 0;
        $next = $highest + 1;
        if ($next > 10 ** $width - 1) {
            throw ValidationException::withMessages(['parent_id' => "No account codes are left under {$parent->code}."]);
        }
        $code = $parent->code.str_pad((string) $next, $width, '0', STR_PAD_LEFT);
        if (Account::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['parent_id' => "The generated code {$code} is already in use."]);
        }

        return $code;
    }

    /** @param array<string, mixed> $data */
    public function update(Account $account, array $data): Account
    {
        $data = $this->lockStructure($account, $data);
        $this->validateHierarchy($data, $account);
        $this->validatePurchaseBillDebitEligibility($data, $account);
        // Direct payment approval is only for an active, postable, debit-normal Income & Expenditure expense ledger.
        if (filter_var($data['direct_payment_eligible'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ($account->level !== AccountLevel::Ledger || $account->account_type !== AccountType::Expense || $account->normal_balance !== NormalBalance::Debit
                || $account->financial_statement !== FinancialStatement::IncomeExpenditure
                || ! filter_var($data['is_postable'] ?? false, FILTER_VALIDATE_BOOLEAN) || ! filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN))) {
            throw ValidationException::withMessages(['direct_payment_eligible' => 'Only an active, postable, debit-normal expense ledger can be approved for direct payment.']);
        }
        $this->guardReferencedClassification($account, $data);

        return DB::transaction(function () use ($account, $data): Account {
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
            if (array_key_exists('is_active', $data) && filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) !== (bool) $locked->is_active) {
                $this->assertActiveChange($locked, filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN));
            }
            $before = $account->only(['code', 'name', 'parent_id', 'account_type', 'level', 'normal_balance', 'financial_statement', 'financial_account_type', 'purchase_bill_debit_eligible', 'direct_payment_eligible', 'is_postable', 'is_active']);
            $account->update($data);
            $this->audit->log('account.updated', $account, ['before' => $before, 'after' => $account->fresh()->only(array_keys($before))]);

            return $account->refresh();
        });
    }

    public function setActive(Account $account, bool $active): void
    {
        DB::transaction(function () use ($account, $active): void {
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
            if ($active) {
                $this->validatePurchaseBillDebitEligibility(['is_active' => true], $locked);
            }
            $this->assertActiveChange($locked, $active);
            $locked->update(['is_active' => $active]);
            $this->audit->log($active ? 'account.activated' : 'account.deactivated', $locked, ['code' => $locked->code]);
        });
        $account->refresh();
    }

    /**
     * The one rule for switching an account on or off, used by setActive() and
     * update() on a locked row: deactivate children first, activate parents
     * first, never deactivate a class or a ledger behind an active cash or bank
     * account, and never cascade.
     */
    private function assertActiveChange(Account $account, bool $active): void
    {
        if ($active) {
            if ($inactive = $this->inactiveAncestorCodes($account)) {
                throw ValidationException::withMessages(['is_active' => 'Activate the parent account(s) first: '.implode(', ', $inactive).'.']);
            }

            return;
        }
        if ($account->level === AccountLevel::ClassLevel) {
            throw ValidationException::withMessages(['is_active' => 'The five account classes are fixed and cannot be deactivated.']);
        }
        if ($descendants = $this->activeDescendantCodes($account)) {
            $shown = array_slice($descendants, 0, 10);
            $more = count($descendants) > 10 ? ' and '.(count($descendants) - 10).' more' : '';
            throw ValidationException::withMessages(['is_active' => 'Deactivate the active child accounts first: '.implode(', ', $shown).$more.'.']);
        }
        if ($account->financialAccounts()->where('is_active', true)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['is_active' => 'This ledger backs an active cash or bank account. Deactivate that account first.']);
        }
    }

    /**
     * Codes of the inactive accounts above $account, nearest first. The rows are
     * locked so a concurrent status change waits for this transaction.
     *
     * @return list<string>
     */
    private function inactiveAncestorCodes(Account $account): array
    {
        $codes = [];
        $seen = [];
        for ($parentId = $account->parent_id; $parentId && ! isset($seen[$parentId]); $parentId = $parent->parent_id) {
            $seen[$parentId] = true;
            $parent = Account::query()->lockForUpdate()->find($parentId, ['id', 'code', 'parent_id', 'is_active']);
            if (! $parent) {
                break;
            }
            if (! $parent->is_active) {
                $codes[] = $parent->code;
            }
        }

        return $codes;
    }

    /**
     * Codes of every active account below $account, locked against concurrent activation.
     *
     * @return list<string>
     */
    private function activeDescendantCodes(Account $account): array
    {
        $codes = [];
        $seen = [$account->id => true];
        for ($level = [$account->id]; $level !== [];) {
            $children = Account::query()->whereIn('parent_id', $level)->lockForUpdate()->orderBy('code')->get(['id', 'code', 'is_active']);
            $level = [];
            foreach ($children as $child) {
                if (isset($seen[$child->id])) {
                    continue;
                }
                $seen[$child->id] = true;
                $level[] = $child->id;
                if ($child->is_active) {
                    $codes[] = $child->code;
                }
            }
        }

        return $codes;
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

    /**
     * The code encodes the hierarchy, so after creation the code, parent, level,
     * type, normal balance and statement are fixed: a request may repeat them
     * but not change them, and the stored values are always used. Groups,
     * Sub Groups and Classes never post and have no cash/bank or Purchase Bill
     * eligibility; a ledger keeps its current settings for any option the
     * request leaves out.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function lockStructure(Account $account, array $data): array
    {
        $fixed = collect(['code', 'parent_id', 'account_type', 'level', 'normal_balance', 'financial_statement'])
            ->mapWithKeys(fn (string $field): array => [$field => $account->getRawOriginal($field)])->all();
        foreach ($fixed as $field => $current) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $current) {
                throw ValidationException::withMessages(['account' => 'The account code, type, level, parent, normal balance and financial statement are fixed after creation.']);
            }
        }
        $data = [...$data, ...$fixed];

        if ($account->level !== AccountLevel::Ledger) {
            if (filter_var($data['is_postable'] ?? false, FILTER_VALIDATE_BOOLEAN) || filled($data['financial_account_type'] ?? null)
                || filter_var($data['purchase_bill_debit_eligible'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || filter_var($data['direct_payment_eligible'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['account' => 'Classes, Groups and Sub Groups cannot allow posting, cash/bank or Purchase Bill eligibility.']);
            }

            return [...$data, 'is_postable' => false, 'financial_account_type' => null, 'purchase_bill_debit_eligible' => false, 'direct_payment_eligible' => false];
        }

        return $data + [
            'is_postable' => (bool) $account->is_postable,
            'financial_account_type' => $account->financial_account_type?->value,
            'purchase_bill_debit_eligible' => (bool) $account->purchase_bill_debit_eligible,
            'direct_payment_eligible' => (bool) $account->direct_payment_eligible,
        ];
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
