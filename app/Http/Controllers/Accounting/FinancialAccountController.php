<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
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
        $accounts = FinancialAccount::query()->with(['account', 'branch'])->whereIn('branch_id', $access->scope($request->user())->select('id'))
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
        $data['account_last_four'] = filled($data['account_number'] ?? null) ? substr(preg_replace('/\s+/', '', $data['account_number']), -4) : null;
        $data['created_by'] = $request->user()->id;
        $account = FinancialAccount::query()->create($data);
        $audit->log('financial_accounts.created', $account, ['branch_id' => $branch->id, 'type' => $account->type]);

        return redirect()->route('financial-accounts.index')->with('status', 'Cash/bank account created.');
    }

    public function edit(Request $request, FinancialAccount $financialAccount, BranchAccess $access): View
    {
        abort_unless($request->user()->can('financial_accounts.manage') && $access->canAccess($request->user(), $financialAccount->branch), 403);

        return view('accounting.financial-accounts.form', [...$this->formData($request, $access), 'financialAccount' => $financialAccount]);
    }

    public function update(StoreFinancialAccountRequest $request, FinancialAccount $financialAccount, BranchAccess $access, AuditLogger $audit): RedirectResponse
    {
        abort_unless($access->canAccess($request->user(), $financialAccount->branch), 403);
        $data = $request->validated();
        $access->scope($request->user())->findOrFail($data['branch_id']);
        if (blank($data['account_number'] ?? null)) {
            unset($data['account_number']);
        } else {
            $data['account_last_four'] = substr(preg_replace('/\s+/', '', $data['account_number']), -4);
        }
        // Once money has moved through the account its ledger, branch and type are frozen:
        // relinking would rewrite history and take the old ledger out of the cash/bank controls.
        // A ledger may be shared by branches, so only this branch's postings make this account used.
        $used = $financialAccount->transactions()->exists() || $this->postedLines(collect([$financialAccount]))->exists();
        if ($used && ((int) $data['account_id'] !== $financialAccount->account_id || (int) $data['branch_id'] !== $financialAccount->branch_id || $data['type'] !== $financialAccount->type)) {
            throw ValidationException::withMessages(['account_id' => 'The ledger, branch and type of a cash or bank account cannot be changed after transactions exist. Deactivate it and create a new account instead.']);
        }
        $financialAccount->update($data);
        $audit->log('financial_accounts.updated', $financialAccount, ['branch_id' => $financialAccount->branch_id, 'type' => $financialAccount->type]);

        return redirect()->route('financial-accounts.index')->with('status', 'Cash/bank account updated.');
    }

    public function toggle(Request $request, FinancialAccount $financialAccount, BranchAccess $access, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('financial_accounts.manage') && $access->canAccess($request->user(), $financialAccount->branch), 403);
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
        $sums = $this->postedLines($accounts)->where('journal_entries.status', 'posted')
            ->groupBy('journal_lines.account_id', 'journal_entries.branch_id')
            ->selectRaw('journal_lines.account_id as account_id, journal_entries.branch_id as branch_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get()->keyBy(fn ($sum): string => $sum->account_id.':'.$sum->branch_id);

        return $accounts->mapWithKeys(function (FinancialAccount $account) use ($sums): array {
            $sum = $sums->get($account->account_id.':'.$account->branch_id);
            $balance = AccountingDecimal::of((string) ($sum->debit ?? '0'))->minus(AccountingDecimal::of((string) ($sum->credit ?? '0')));

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
                    $query->orWhere(fn ($pair) => $pair->where('journal_lines.account_id', $account->account_id)->where('journal_entries.branch_id', $account->branch_id));
                }
            });
    }

    private function formData(Request $request, BranchAccess $access): array
    {
        return [
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'ledgers' => Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(),
        ];
    }
}
