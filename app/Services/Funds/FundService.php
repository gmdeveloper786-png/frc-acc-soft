<?php

namespace App\Services\Funds;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Fund;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and edits funds. Fund details and permitted ledgers need
 * funds.manage; the grant fields (donor, amount, period) need grants.manage.
 * Fields the user may not change are left exactly as they were.
 */
class FundService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Fund
    {
        abort_unless($actor->is_active && $actor->can('funds.manage'), 403);

        return DB::transaction(function () use ($data, $actor): Fund {
            $attributes = $this->fundAttributes($data, null);
            if ($actor->can('grants.manage')) {
                $attributes = [...$attributes, ...$this->grantAttributes($data)];
            }
            $fund = Fund::query()->create([...$attributes, 'created_by' => $actor->id]);
            $accounts = $this->permittedAccountIds($data);
            $fund->permittedAccounts()->sync($accounts);
            $this->audit->log('funds.created', $fund, [...$this->auditable($attributes), 'permitted_account_ids' => $accounts], $actor);

            return $fund;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Fund $fund, array $data, User $actor): Fund
    {
        $managesFunds = $actor->can('funds.manage');
        $managesGrants = $actor->can('grants.manage');
        abort_unless($actor->is_active && ($managesFunds || $managesGrants), 403);

        return DB::transaction(function () use ($fund, $data, $actor, $managesFunds, $managesGrants): Fund {
            $fund = Fund::query()->lockForUpdate()->findOrFail($fund->id);
            $before = $this->auditable($fund->only([...$fund->getFillable()]));
            $beforeAccounts = $fund->permittedAccounts()->pluck('accounts.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

            $attributes = [];
            if ($managesFunds) {
                $attributes = $this->fundAttributes($data, $fund);
            }
            if ($managesGrants) {
                $attributes = [...$attributes, ...$this->grantAttributes($data)];
            }
            $fund->update($attributes);
            $accounts = $beforeAccounts;
            if ($managesFunds) {
                $accounts = $this->permittedAccountIds($data);
                $fund->permittedAccounts()->sync($accounts);
            }

            $after = $this->auditable($fund->refresh()->only([...$fund->getFillable()]));
            $changes = [];
            foreach ($after as $key => $value) {
                if (($before[$key] ?? null) !== $value) {
                    $changes[$key] = ['old' => $before[$key] ?? null, 'new' => $value];
                }
            }
            if ($accounts !== $beforeAccounts) {
                $changes['permitted_account_ids'] = ['old' => $beforeAccounts, 'new' => $accounts];
            }
            $this->audit->log('funds.updated', $fund, ['changes' => $changes], $actor);

            return $fund;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fundAttributes(array $data, ?Fund $fund): array
    {
        $code = strtoupper(trim((string) $data['code']));
        if (Fund::query()->where('code', $code)->when($fund, fn ($query) => $query->whereKeyNot($fund->id))->exists()) {
            throw ValidationException::withMessages(['code' => 'This fund code is already in use.']);
        }
        $parentId = filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null;
        if ($parentId !== null) {
            $parent = Fund::query()->find($parentId);
            // One level only (GRT-01 under GRT): a sub-fund cannot itself be a parent.
            if ($parent === null || $parent->parent_id !== null || $parentId === $fund?->id) {
                throw ValidationException::withMessages(['parent_id' => 'Choose a top-level fund as the parent.']);
            }
            if ($fund !== null && $fund->children()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'This fund has sub-funds, so it cannot be placed under another fund.']);
            }
        }
        if (! array_key_exists($data['type'] ?? '', Fund::TYPES) || ! array_key_exists($data['category'] ?? '', Fund::CATEGORIES)) {
            throw ValidationException::withMessages(['type' => 'Choose a valid fund type and category.']);
        }

        return [
            'code' => $code, 'name' => trim((string) $data['name']), 'type' => $data['type'], 'category' => $data['category'],
            'parent_id' => $parentId, 'restriction_notes' => filled($data['restriction_notes'] ?? null) ? trim((string) $data['restriction_notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function grantAttributes(array $data): array
    {
        $amount = filled($data['grant_amount'] ?? null) ? AccountingDecimal::of($data['grant_amount']) : null;
        if ($amount !== null && $amount->isNegative()) {
            throw ValidationException::withMessages(['grant_amount' => 'The grant amount cannot be negative.']);
        }
        $start = filled($data['start_date'] ?? null) ? (string) $data['start_date'] : null;
        $end = filled($data['end_date'] ?? null) ? (string) $data['end_date'] : null;
        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }

        return [
            'donor_name' => filled($data['donor_name'] ?? null) ? trim((string) $data['donor_name']) : null,
            'grant_amount' => $amount !== null ? AccountingDecimal::string($amount) : null,
            'start_date' => $start, 'end_date' => $end,
        ];
    }

    /**
     * Permitted ledgers must be active postable expense or asset ledgers.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function permittedAccountIds(array $data): array
    {
        $ids = collect($data['permitted_account_ids'] ?? [])->filter(fn ($id): bool => filled($id))->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $valid = Account::query()->whereKey($ids)->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)
            ->whereIn('account_type', [AccountType::Expense->value, AccountType::Asset->value])->count();
        if ($valid !== count($ids)) {
            throw ValidationException::withMessages(['permitted_account_ids' => 'Permitted ledgers must be active, postable expense or asset ledgers.']);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditable(array $attributes): array
    {
        return collect($attributes)->except(['created_by'])->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->all();
    }
}
