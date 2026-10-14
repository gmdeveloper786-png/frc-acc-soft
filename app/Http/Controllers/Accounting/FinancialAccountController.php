<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialAccountRequest;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('accounting.financial-accounts.index', compact('accounts'));
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
        $used = $financialAccount->transactions()->exists() || DB::table('journal_lines')->where('account_id', $financialAccount->account_id)->exists();
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

    private function formData(Request $request, BranchAccess $access): array
    {
        return [
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'ledgers' => Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(),
        ];
    }
}
