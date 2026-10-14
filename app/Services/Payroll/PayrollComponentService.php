<?php

namespace App\Services\Payroll;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\PayrollComponentType;
use App\Models\Account;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll components are plain configuration: a name, a direction and an
 * optional ledger. They carry no rate, bracket or formula.
 */
class PayrollComponentService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PayrollComponent
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data): PayrollComponent {
            $type = PayrollComponentType::from($data['type']);
            $component = PayrollComponent::query()->create([...$this->attributes($data, $type), 'code' => strtoupper(trim((string) $data['code'])), 'type' => $type, 'category' => $data['category']]);
            $this->audit->log('payroll_components.created', $component, ['code' => $component->code, 'type' => $type->value, 'account_id' => $component->account_id]);

            return $component;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PayrollComponent $component, array $data, User $actor): PayrollComponent
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($component, $data): PayrollComponent {
            $component = PayrollComponent::query()->lockForUpdate()->findOrFail($component->id);
            $type = PayrollComponentType::from($data['type']);
            if ($type !== $component->type && $component->runLineItems()->exists()) {
                throw ValidationException::withMessages(['type' => 'The type of a component that is already used on a payroll run cannot change. Deactivate it and create a new component.']);
            }
            $before = $component->only(['name', 'type', 'category', 'account_id', 'is_active']);
            $component->update([...$this->attributes($data, $type), 'type' => $type, 'category' => $data['category']]);
            $this->audit->log('payroll_components.updated', $component, ['code' => $component->code, 'previous_account_id' => $before['account_id'], 'account_id' => $component->account_id, 'is_active' => $component->is_active]);

            return $component;
        });
    }

    /**
     * Ledgers a component of this type may point to: an expense ledger for an
     * earning; a liability or asset ledger (tax payable, staff advances) for a
     * deduction.
     *
     * @return Collection<int, Account>
     */
    public function eligibleAccounts(PayrollComponentType $type): Collection
    {
        return Account::query()->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)
            ->whereIn('account_type', $this->accountTypes($type))->orderBy('code')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, PayrollComponentType $type): array
    {
        $accountId = filled($data['account_id'] ?? null) ? (int) $data['account_id'] : null;
        if ($accountId !== null && ! Account::query()->whereKey($accountId)->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)->whereIn('account_type', $this->accountTypes($type))->exists()) {
            throw ValidationException::withMessages(['account_id' => $type === PayrollComponentType::Earning
                ? 'An earning may only override Salary Expense with an active, postable expense ledger.'
                : 'A deduction needs an active, postable liability or asset ledger.']);
        }

        return ['name' => trim((string) $data['name']), 'account_id' => $accountId, 'is_active' => (bool) ($data['is_active'] ?? false), 'sort_order' => (int) ($data['sort_order'] ?? 0)];
    }

    /** @return list<string> */
    private function accountTypes(PayrollComponentType $type): array
    {
        return $type === PayrollComponentType::Earning ? [AccountType::Expense->value] : [AccountType::Liability->value, AccountType::Asset->value];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('payroll_components.manage'), 403);
    }
}
