<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\FinancialAccountActivationGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinancialAccountController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $accounts = FinancialAccount::query()->with(['account', 'branch'])->bookVisibleTo($request->user(), $access->scope($request->user())->select('id'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->string('status') === 'active'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')->orWhere('bank_name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('name')->paginate(20)->withQueryString();
        $balances = $this->balances($accounts->getCollection());

        return view('accounting.financial-accounts.index', compact('accounts', 'balances'));
    }

    public function create(Request $request, BranchAccess $access): View
    {
        abort_unless($request->user()->can('financial_accounts.manage'), 403);

        return view('accounting.financial-accounts.form', $this->formData($request, $access));
    }

    public function store(StoreFinancialAccountRequest $request, BranchAccess $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $branch = $access->scope($request->user())->findOrFail($data['branch_id']);
        $this->authorizeScope($request, $data);
        $data['account_last_four'] = filled($data['account_number'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9]/', '', $data['account_number']), -4) : null;
        unset($data['keep_account_number']);
        $data['created_by'] = $request->user()->id;
        $account = FinancialAccount::query()->create($data);
        $audit->log('financial_accounts.created', $account, ['branch_id' => $branch->id, 'type' => $account->type]);

        return redirect()->route('financial-accounts.index')->with('status', 'Cash/bank account created.');
    }

    public function edit(Request $request, FinancialAccount $financialAccount, BranchAccess $access): View
    {
        abort_unless($request->user()->can('financial_accounts.manage') && $financialAccount->bookVisibleTo($request->user(), $access->canAccess($request->user(), $financialAccount->branch)), 403);

        return view('accounting.financial-accounts.form', [...$this->formData($request, $access), 'financialAccount' => $financialAccount]);
    }

    public function update(StoreFinancialAccountRequest $request, FinancialAccount $financialAccount, BranchAccess $access, AuditLogger $audit): RedirectResponse
    {
        abort_unless($financialAccount->bookVisibleTo($request->user(), $access->canAccess($request->user(), $financialAccount->branch)), 403);
        $data = $request->validated();
        unset($data['keep_account_number']);
        $access->scope($request->user())->findOrFail($data['branch_id']);
        $this->authorizeScope($request, $data);
        if (blank($data['account_number'] ?? null)) {
            unset($data['account_number']);
        } else {
            $data['account_last_four'] = substr(preg_replace('/[^A-Za-z0-9]/', '', $data['account_number']), -4);
        }
        // Once money has moved through the account its ledger, branch and type are frozen:
        // relinking would rewrite history and take the old ledger out of the cash/bank controls.
        // A ledger may be shared by branches, so only this branch's postings make this account used.
        $used = $financialAccount->transactions()->exists() || $this->postedLines(collect([$financialAccount]))->exists();
        if ($used && ((int) $data['account_id'] !== $financialAccount->account_id || (int) $data['branch_id'] !== $financialAccount->branch_id || $data['type'] !== $financialAccount->type)) {
            throw ValidationException::withMessages(['account_id' => 'The ledger, branch and type of a cash or bank account cannot be changed after transactions exist. Deactivate it and create a new account instead.']);
        }
        // A used account may become shared (its postings stay where they are), but a shared one may not
        // become branch-only again: other branches' postings would drop out of its book and reconciliation.
        if ($used && $financialAccount->isShared() && ($data['scope'] ?? FinancialAccount::SCOPE_BRANCH) !== FinancialAccount::SCOPE_ORGANIZATION) {
            throw ValidationException::withMessages(['scope' => 'A shared bank account that has transactions cannot be limited to one branch.']);
        }
        $financialAccount->update($data);
        $audit->log('financial_accounts.updated', $financialAccount, ['branch_id' => $financialAccount->branch_id, 'type' => $financialAccount->type]);

        return redirect()->route('financial-accounts.index')->with('status', 'Cash/bank account updated.');
    }

    public function toggle(Request $request, FinancialAccount $financialAccount, BranchAccess $access, AuditLogger $audit, FinancialAccountActivationGuard $activationGuard): RedirectResponse
    {
        abort_unless($request->user()->can('financial_accounts.manage') && $financialAccount->bookVisibleTo($request->user(), $access->canAccess($request->user(), $financialAccount->branch)), 403);
        if (! $financialAccount->is_active) {
            $activationGuard->assertCanActivate($financialAccount);
        }
        $financialAccount->update(['is_active' => ! $financialAccount->is_active]);
        $audit->log('financial_accounts.status_changed', $financialAccount, ['is_active' => $financialAccount->is_active]);

        return back()->with('status', 'Account status updated.');
    }

    /**
     * Posted balance of each cash/bank account: its ledger's lines in its own branch.
     *
     * @param  Collection<int, FinancialAccount>  $accounts
     * @return array<int, string> Keyed by financial account id.
     */
    private function balances(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        $sums = $this->postedLines($accounts)->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->groupBy('journal_lines.account_id', 'journal_entries.branch_id')
            ->selectRaw('journal_lines.account_id as account_id, journal_entries.branch_id as branch_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get()->keyBy(fn ($sum): string => $sum->account_id.':'.$sum->branch_id);

        return $accounts->mapWithKeys(function (FinancialAccount $account) use ($sums): array {
            // A shared account's balance is its ledger across every branch.
            $lines = $account->isShared()
                ? $sums->filter(fn ($sum): bool => (int) $sum->account_id === $account->account_id)
                : $sums->only([$account->account_id.':'.$account->branch_id]);
            $balance = AccountingDecimal::of('0');
            foreach ($lines as $sum) {
                $balance = $balance->plus(AccountingDecimal::of((string) $sum->debit))->minus(AccountingDecimal::of((string) $sum->credit));
            }

            return [$account->id => AccountingDecimal::string($balance)];
        })->all();
    }

    /**
     * Journal lines on the accounts' ledgers within each account's own branch.
     *
     * @param  Collection<int, FinancialAccount>  $accounts
     */
    private function postedLines(Collection $accounts): Builder
    {
        return DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where(function ($query) use ($accounts): void {
                foreach ($accounts as $account) {
                    $query->orWhere(fn ($pair) => $pair->where('journal_lines.account_id', $account->account_id)
                        ->when(! $account->isShared(), fn ($own) => $own->where('journal_entries.branch_id', $account->branch_id)));
                }
            });
    }

    /** Only a user with access to every branch may create or keep a shared (all branches) account. */
    private function authorizeScope(Request $request, array $data): void
    {
        abort_if(($data['scope'] ?? FinancialAccount::SCOPE_BRANCH) === FinancialAccount::SCOPE_ORGANIZATION && ! $request->user()->can('branches.view_all'), 403);
    }

    private function formData(Request $request, BranchAccess $access): array
    {
        $ledgers = Account::query()
            ->where('level', 'ledger')
            ->where('account_type', 'asset')
            ->where('normal_balance', 'debit')
            ->where('financial_statement', 'balance_sheet')
            ->whereIn('financial_account_type', ['cash', 'bank'])
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return [
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'ledgersByType' => collect(['cash', 'bank'])->mapWithKeys(fn (string $type): array => [
                $type => $ledgers->filter(fn (Account $account): bool => $account->financial_account_type?->value === $type)->values(),
            ]),
        ];
    }
}
