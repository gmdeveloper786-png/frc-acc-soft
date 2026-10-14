<?php

namespace App\Services\Assets;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asset categories are configurable records that carry the three General
 * Ledger accounts every asset of the category will use.
 */
class AssetCategoryService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): AssetCategory
    {
        abort_unless($actor->is_active && $actor->can('asset_categories.manage'), 403);

        return DB::transaction(function () use ($data, $actor): AssetCategory {
            $attributes = $this->attributes($data);
            $this->assertUniqueCode($attributes['code'], null);
            $this->assertAccounts((int) $attributes['asset_account_id'], (int) $attributes['accumulated_depreciation_account_id'], (int) $attributes['depreciation_expense_account_id']);
            $category = AssetCategory::query()->create([...$attributes, 'created_by' => $actor->id]);
            $this->audit->log('asset_categories.created', $category, $attributes);

            return $category;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(AssetCategory $category, array $data, User $actor): AssetCategory
    {
        abort_unless($actor->is_active && $actor->can('asset_categories.manage'), 403);

        return DB::transaction(function () use ($category, $data, $actor): AssetCategory {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            $attributes = $this->attributes($data);
            $this->assertUniqueCode($attributes['code'], $category->id);
            $this->assertAccounts((int) $attributes['asset_account_id'], (int) $attributes['accumulated_depreciation_account_id'], (int) $attributes['depreciation_expense_account_id']);
            $previous = $category->only(array_keys($attributes));
            $category->update($attributes);
            // Assets keep the accounts copied at registration; only new assets follow the change.
            $this->audit->log('asset_categories.updated', $category, ['previous' => $previous, 'current' => $attributes, 'updated_by' => $actor->id]);

            return $category;
        });
    }

    /**
     * The three ledgers an asset posts to. The accumulated depreciation ledger
     * is a contra-asset: an asset account that normally carries a credit balance.
     */
    public function assertAccounts(int $assetAccountId, int $accumulatedAccountId, int $expenseAccountId): void
    {
        $errors = [];
        if (! $this->ledger($assetAccountId, AccountType::Asset, NormalBalance::Debit)) {
            $errors['asset_account_id'] = 'The asset account must be an active postable asset ledger with a debit normal balance.';
        }
        if (! $this->ledger($accumulatedAccountId, AccountType::Asset, NormalBalance::Credit)) {
            $errors['accumulated_depreciation_account_id'] = 'The accumulated depreciation account must be an active postable asset ledger with a credit normal balance (contra-asset).';
        }
        if (! $this->ledger($expenseAccountId, AccountType::Expense, null)) {
            $errors['depreciation_expense_account_id'] = 'The depreciation expense account must be an active postable expense ledger.';
        }
        if (count(array_unique([$assetAccountId, $accumulatedAccountId, $expenseAccountId])) !== 3) {
            $errors['accumulated_depreciation_account_id'] ??= 'The asset, accumulated depreciation and depreciation expense accounts must be three different ledgers.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ledger(int $accountId, AccountType $type, ?NormalBalance $normalBalance): bool
    {
        return Account::query()->whereKey($accountId)->where('account_type', $type->value)->where('level', AccountLevel::Ledger->value)
            ->where('is_postable', true)->where('is_active', true)
            ->when($normalBalance, fn ($query) => $query->where('normal_balance', $normalBalance->value))
            ->exists();
    }

    private function assertUniqueCode(string $code, ?int $ignoreId): void
    {
        if (AssetCategory::query()->where('code', $code)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            throw ValidationException::withMessages(['code' => 'This category code is already in use.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $life = (int) $data['default_useful_life_months'];
        if ($life < 1) {
            throw ValidationException::withMessages(['default_useful_life_months' => 'The default useful life must be at least one month.']);
        }

        return [
            'code' => strtoupper(trim((string) $data['code'])), 'name' => trim((string) $data['name']), 'description' => $data['description'] ?? null,
            'default_useful_life_months' => $life, 'asset_account_id' => (int) $data['asset_account_id'],
            'accumulated_depreciation_account_id' => (int) $data['accumulated_depreciation_account_id'],
            'depreciation_expense_account_id' => (int) $data['depreciation_expense_account_id'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
