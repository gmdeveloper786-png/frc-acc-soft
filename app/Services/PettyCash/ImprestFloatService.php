<?php

namespace App\Services\PettyCash;

use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\ImprestFloat;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AccountMappingService;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets up and changes imprest floats. The float amount is always entered by a
 * user; nothing here assumes or defaults one.
 */
class ImprestFloatService
{
    public function __construct(private BranchAccess $branchAccess, private AuditLogger $audit, private AccountMappingService $mappings) {}

    /**
     * Ledgers that may hold a branch's petty cash: the petty cash mapping ledger and
     * the ledgers of the branch's active cash accounts.
     *
     * @return Collection<int, Account>
     */
    public function candidateLedgers(int $branchId): Collection
    {
        $mapped = AccountMapping::query()->where('mapping_key', AccountMappingKey::PettyCash->value)->pluck('account_id');
        $cash = FinancialAccount::query()->where('branch_id', $branchId)->where('type', 'cash')->where('is_active', true)->pluck('account_id');

        return Account::query()->whereKey($mapped->merge($cash)->unique()->values())->orderBy('code')->get()
            ->filter(fn (Account $account): bool => $this->mappings->isUsable($account, AccountType::Asset))->values();
    }

    /** @return Collection<int, FinancialAccount> Active bank and cash accounts of the branch with a usable ledger. */
    public function candidateSources(int $branchId): Collection
    {
        return FinancialAccount::query()->with('account')->usableIn($branchId)->whereIn('type', ['bank', 'cash'])->where('is_active', true)->orderBy('type')->orderBy('name')->get()
            ->filter(fn (FinancialAccount $account): bool => $this->mappings->isUsable($account->account, AccountType::Asset))->values();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): ImprestFloat
    {
        abort_unless($actor->can('imprest.manage'), 403);

        return DB::transaction(function () use ($data, $actor): ImprestFloat {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
            $accountId = (int) $data['account_id'];
            $this->assertLedger($branch->id, $accountId);
            $this->assertSource($branch->id, (int) $data['source_financial_account_id'], $accountId);
            $amount = $this->amount($data['float_amount']);
            $this->assertSingleActive($branch->id, $accountId, null);

            $float = ImprestFloat::query()->create([
                'branch_id' => $branch->id, 'account_id' => $accountId, 'float_amount' => $amount,
                'source_financial_account_id' => (int) $data['source_financial_account_id'],
                'custodian_name' => trim((string) $data['custodian_name']), 'is_active' => true, 'created_by' => $actor->id,
            ]);
            $this->audit->log('imprest.float_created', $float, [
                'branch_id' => $branch->id, 'account_id' => $accountId, 'float_amount' => $amount,
                'source_financial_account_id' => $float->source_financial_account_id, 'custodian_name' => $float->custodian_name,
            ], $actor);

            return $float;
        });
    }

    /**
     * Change the amount, source, custodian or active state. The branch and ledger of a
     * float are fixed; a different ledger is a new float.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ImprestFloat $float, array $data, User $actor): ImprestFloat
    {
        abort_unless($actor->can('imprest.manage'), 403);

        return DB::transaction(function () use ($float, $data, $actor): ImprestFloat {
            $float = ImprestFloat::query()->with('branch')->lockForUpdate()->findOrFail($float->id);
            abort_unless($this->branchAccess->canAccess($actor, $float->branch), 403);
            $isActive = (bool) ($data['is_active'] ?? false);
            if ($isActive) {
                $this->assertLedger($float->branch_id, $float->account_id);
                $this->assertSingleActive($float->branch_id, $float->account_id, $float->id);
            }
            $this->assertSource($float->branch_id, (int) $data['source_financial_account_id'], $float->account_id);

            $before = $this->auditable($float);
            $float->fill([
                'float_amount' => $this->amount($data['float_amount']),
                'source_financial_account_id' => (int) $data['source_financial_account_id'],
                'custodian_name' => trim((string) $data['custodian_name']),
                'is_active' => $isActive, 'updated_by' => $actor->id,
            ])->save();
            $after = $this->auditable($float);
            $changed = array_keys(array_diff_assoc($after, $before));
            if ($changed !== []) {
                $this->audit->log('imprest.float_updated', $float, [
                    'branch_id' => $float->branch_id,
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'new' => array_intersect_key($after, array_flip($changed)),
                ], $actor);
            }

            return $float;
        });
    }

    private function amount(mixed $value): string
    {
        try {
            $amount = AccountingDecimal::of($value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['float_amount' => 'Enter the float amount as a number with at most four decimal places.']);
        }
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['float_amount' => 'The float amount must be greater than zero.']);
        }

        return AccountingDecimal::string($amount);
    }

    private function assertLedger(int $branchId, int $accountId): void
    {
        if (! $this->candidateLedgers($branchId)->contains('id', $accountId)) {
            throw ValidationException::withMessages(['account_id' => 'The petty cash ledger must be the petty cash mapping ledger or the ledger of an active cash account of this branch, and must be an active, postable asset ledger.']);
        }
    }

    private function assertSource(int $branchId, int $sourceId, int $ledgerId): void
    {
        $source = $this->candidateSources($branchId)->firstWhere('id', $sourceId);
        if (! $source) {
            throw ValidationException::withMessages(['source_financial_account_id' => 'The replenishment source must be an active bank or cash account of the same branch, linked to an active postable asset ledger.']);
        }
        if ($source->account_id === $ledgerId) {
            throw ValidationException::withMessages(['source_financial_account_id' => 'The replenishment source cannot use the petty cash ledger itself.']);
        }
    }

    private function assertSingleActive(int $branchId, int $accountId, ?int $exceptId): void
    {
        $exists = ImprestFloat::query()->where('branch_id', $branchId)->where('account_id', $accountId)->where('is_active', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->lockForUpdate()->exists();
        if ($exists) {
            throw ValidationException::withMessages(['account_id' => 'This branch already has an active imprest float on this petty cash ledger.']);
        }
    }

    /** @return array<string, string> */
    private function auditable(ImprestFloat $float): array
    {
        return [
            'float_amount' => AccountingDecimal::string(AccountingDecimal::of($float->float_amount)),
            'source_financial_account_id' => (string) $float->source_financial_account_id,
            'custodian_name' => (string) $float->custodian_name,
            'is_active' => $float->is_active ? 'yes' : 'no',
        ];
    }
}
