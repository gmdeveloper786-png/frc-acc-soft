<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\FinancialAccount;
use App\Models\FrcService;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountMappingService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Map or clear an organisation-level control account.
     */
    public function set(AccountMappingKey $key, ?int $accountId, User $actor): ?AccountMapping
    {
        abort_unless($actor->can('account_mappings.manage'), 403);

        return DB::transaction(function () use ($key, $accountId, $actor): ?AccountMapping {
            $mapping = AccountMapping::query()->where('mapping_key', $key->value)->lockForUpdate()->first();
            $before = $mapping?->account_id;

            if ($accountId === null) {
                $mapping?->delete();
                if ($before !== null) {
                    $this->audit->log('account_mappings.cleared', null, ['mapping_key' => $key->value, 'previous_account_id' => $before]);
                }

                return null;
            }

            $account = $this->assertEligible($accountId, $key->accountType(), 'account_id');
            $conflict = AccountMapping::query()->where('account_id', $account->id)->where('mapping_key', '!=', $key->value)->first();
            if ($conflict) {
                throw ValidationException::withMessages(['account_id' => "Ledger {$account->code} is already mapped to {$conflict->mapping_key->label()}. One ledger cannot serve two control mappings."]);
            }
            if ($key !== AccountMappingKey::PettyCash && FinancialAccount::query()->where('account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['account_id' => "Ledger {$account->code} is already linked to a cash or bank account."]);
            }

            $mapping = AccountMapping::query()->updateOrCreate(['mapping_key' => $key->value], ['account_id' => $account->id, 'updated_by' => $actor->id]);
            if ($before !== $account->id) {
                $this->audit->log('account_mappings.updated', $mapping, ['mapping_key' => $key->value, 'previous_account_id' => $before, 'account_id' => $account->id, 'account_code' => $account->code]);
            }

            return $mapping;
        });
    }

    /**
     * Map or clear the fee income ledger of one FRC service.
     */
    public function setServiceRevenue(FrcService $service, ?int $accountId, User $actor): FrcService
    {
        abort_unless($actor->can('account_mappings.manage'), 403);

        return DB::transaction(function () use ($service, $accountId): FrcService {
            $before = $service->revenue_account_id;
            $account = $accountId === null ? null : $this->assertEligible($accountId, AccountType::Income, 'account_id');
            $service->update(['revenue_account_id' => $account?->id]);
            if ($before !== $account?->id) {
                $this->audit->log('account_mappings.service_revenue_updated', $service, ['frc_service_id' => $service->frc_service_id, 'previous_account_id' => $before, 'account_id' => $account?->id]);
            }

            return $service->refresh();
        });
    }

    /**
     * Return the mapped ledger or stop with a configuration error.
     */
    public function resolve(AccountMappingKey $key): Account
    {
        $mapping = AccountMapping::query()->with('account')->where('mapping_key', $key->value)->first();
        if (! $mapping) {
            throw ValidationException::withMessages(['mapping' => "Configuration error: the {$key->label()} GL mapping is not configured. Ask an authorized user to complete it under Accounting Setup → GL Mapping."]);
        }
        if (! $this->isUsable($mapping->account, $key->accountType())) {
            throw ValidationException::withMessages(['mapping' => "Configuration error: the {$key->label()} GL mapping points to a ledger that is no longer an active, postable {$key->accountType()->value} account."]);
        }

        return $mapping->account;
    }

    /** Payable ledger for purchase bills: the dedicated control account, else Accounts Payable. */
    public function payableForBills(): Account
    {
        $control = AccountMapping::query()->with('account')->where('mapping_key', AccountMappingKey::PurchaseBillControl->value)->first();

        return $control ? $this->resolve(AccountMappingKey::PurchaseBillControl) : $this->resolve(AccountMappingKey::AccountsPayable);
    }

    /** Fee income ledger for a service: its own ledger, else General Fee Income. */
    public function feeIncomeFor(?int $serviceId): Account
    {
        $service = $serviceId ? FrcService::query()->with('revenueAccount')->find($serviceId) : null;
        if ($service?->revenue_account_id) {
            if (! $this->isUsable($service->revenueAccount, AccountType::Income)) {
                throw ValidationException::withMessages(['mapping' => "Configuration error: the fee income GL mapping of service {$service->name} points to a ledger that is no longer an active, postable income account."]);
            }

            return $service->revenueAccount;
        }

        return $this->resolve(AccountMappingKey::FeeIncomeGeneral);
    }

    /**
     * Fee income ledger for a synchronised FRC payment or enrollment: only the service's own
     * ledger. General Fee Income is deliberately not used, so an unmapped service stays unposted
     * instead of being credited to whichever ledger happens to be the general one.
     */
    public function serviceFeeIncome(int $serviceId): Account
    {
        $service = FrcService::query()->with('revenueAccount')->find($serviceId);
        if (! $service?->revenue_account_id) {
            $name = $service->name ?? "#{$serviceId}";
            throw ValidationException::withMessages(['mapping' => "Service {$name} has no fee income ledger. Set it under GL Mapping → Service-specific fee income before posting its FRC payments; General Fee Income is not used for synchronised payments."]);
        }
        if (! $this->isUsable($service->revenueAccount, AccountType::Income)) {
            throw ValidationException::withMessages(['mapping' => "Configuration error: the fee income ledger of service {$service->name} is not an active, postable income account. Correct it under GL Mapping → Service-specific fee income."]);
        }

        return $service->revenueAccount;
    }

    public function isUsable(?Account $account, AccountType $type): bool
    {
        return $account !== null
            && $account->is_active
            && $account->is_postable
            && $account->level === AccountLevel::Ledger
            && $account->account_type === $type
            && $account->normal_balance === $type->defaultNormalBalance();
    }

    /** @return Collection<int, Account> */
    public function eligibleAccounts(AccountType $type): Collection
    {
        return Account::query()->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)
            ->where('account_type', $type->value)->where('normal_balance', $type->defaultNormalBalance()->value)
            ->orderBy('code')->get();
    }

    private function assertEligible(int $accountId, AccountType $type, string $field): Account
    {
        $account = Account::query()->find($accountId);
        if (! $account) {
            throw ValidationException::withMessages([$field => 'The selected ledger does not exist.']);
        }
        if (! $account->is_active) {
            throw ValidationException::withMessages([$field => "Ledger {$account->code} is inactive and cannot be mapped."]);
        }
        if (! $account->is_postable || $account->level !== AccountLevel::Ledger) {
            throw ValidationException::withMessages([$field => "Ledger {$account->code} is not a postable ledger account."]);
        }
        if ($account->account_type !== $type || $account->normal_balance !== $type->defaultNormalBalance()) {
            throw ValidationException::withMessages([$field => "Ledger {$account->code} is not compatible: this mapping requires a {$type->defaultNormalBalance()->value}-balance {$type->value} account."]);
        }

        return $account;
    }
}
