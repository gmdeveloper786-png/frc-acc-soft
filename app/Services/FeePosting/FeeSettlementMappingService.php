<?php

namespace App\Services\FeePosting;

use App\Enums\AccountType;
use App\Models\Branch;
use App\Models\FeeSettlementMapping;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountMappingService;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which cash or bank account each FRC payment method of a branch settles
 * into. Nothing is assumed: every method of every branch is mapped explicitly.
 */
class FeeSettlementMappingService
{
    /** FRC payment methods, in display order. */
    public const METHODS = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
        'easypaisa' => 'Easypaisa',
        'jazzcash' => 'JazzCash',
        'other' => 'Other',
    ];

    public function __construct(private BranchAccess $branchAccess, private AccountMappingService $accountMappings, private AuditLogger $audit) {}

    /** Financial account type a method must settle into. */
    public static function requiredType(string $method): string
    {
        return $method === 'cash' ? 'cash' : 'bank';
    }

    /**
     * Save or clear the mapping of one branch and method.
     */
    public function save(Branch $branch, string $method, ?int $financialAccountId, User $actor): ?FeeSettlementMapping
    {
        abort_unless($actor->can('fee_settlement.manage'), 403);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
        if (! array_key_exists($method, self::METHODS)) {
            throw ValidationException::withMessages(['payment_method' => 'Unknown FRC payment method.']);
        }

        return DB::transaction(function () use ($branch, $method, $financialAccountId, $actor): ?FeeSettlementMapping {
            $mapping = FeeSettlementMapping::query()->where('branch_id', $branch->id)->where('payment_method', $method)->lockForUpdate()->first();
            $before = $mapping?->financial_account_id;

            if ($financialAccountId === null) {
                if ($mapping) {
                    $mapping->delete();
                    $this->audit->log('fee_settlement.mapping_cleared', $branch, ['branch_id' => $branch->id, 'payment_method' => $method, 'old_financial_account_id' => $before, 'new_financial_account_id' => null]);
                }

                return null;
            }

            $account = FinancialAccount::query()->with('account')->find($financialAccountId);
            $issue = $this->accountIssue($account, $branch->id, $method);
            if ($issue !== null) {
                throw ValidationException::withMessages(['financial_account_id' => $issue]);
            }

            $mapping = FeeSettlementMapping::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'payment_method' => $method],
                ['financial_account_id' => $account->id, 'updated_by' => $actor->id],
            );
            if ($before !== $account->id) {
                $this->audit->log('fee_settlement.mapping_updated', $mapping, [
                    'branch_id' => $branch->id, 'payment_method' => $method,
                    'old_financial_account_id' => $before, 'new_financial_account_id' => $account->id,
                    'new_ledger_code' => $account->account?->code,
                ]);
            }

            return $mapping;
        });
    }

    /**
     * The settlement account of a branch and method, or the reason it cannot be used.
     *
     * @return array{account: ?FinancialAccount, issue: ?string}
     */
    public function resolve(?int $branchId, string $method): array
    {
        if ($branchId === null) {
            return ['account' => null, 'issue' => 'The payment has no local branch, so no settlement account can be chosen.'];
        }
        $mapping = FeeSettlementMapping::query()->with('financialAccount.account')->where('branch_id', $branchId)->where('payment_method', $method)->first();
        if (! $mapping) {
            $label = self::METHODS[$method] ?? $method;

            return ['account' => null, 'issue' => "No settlement account is mapped for {$label} payments of this branch."];
        }
        $issue = $this->accountIssue($mapping->financialAccount, $branchId, $method);

        return $issue === null ? ['account' => $mapping->financialAccount, 'issue' => null] : ['account' => null, 'issue' => 'The settlement mapping is no longer valid: '.$issue];
    }

    /** Why an account cannot settle this branch and method, or null when it can. */
    public function accountIssue(?FinancialAccount $account, int $branchId, string $method): ?string
    {
        if (! $account) {
            return 'The selected cash or bank account does not exist.';
        }
        if (! $account->is_active) {
            return "Account {$account->name} is inactive.";
        }
        if ((int) $account->branch_id !== $branchId) {
            return "Account {$account->name} belongs to another branch.";
        }
        $required = self::requiredType($method);
        if ($account->type !== $required) {
            return $required === 'cash'
                ? 'Cash payments must settle into a cash account.'
                : (self::METHODS[$method] ?? $method).' payments must settle into a bank-type account (wallets and card clearing are bank-type accounts).';
        }
        if (! $this->accountMappings->isUsable($account->account, AccountType::Asset)) {
            return "The ledger of account {$account->name} is not an active, postable asset ledger.";
        }

        return null;
    }

    /** @return Collection<int, FinancialAccount> */
    public function candidateAccounts(int $branchId): Collection
    {
        return FinancialAccount::query()->with('account')->where('branch_id', $branchId)->where('is_active', true)->orderBy('type')->orderBy('name')->get()
            ->filter(fn (FinancialAccount $account): bool => $this->accountMappings->isUsable($account->account, AccountType::Asset))->values();
    }
}
