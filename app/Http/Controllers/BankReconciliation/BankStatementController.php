<?php

namespace App\Http\Controllers\BankReconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankReconciliation\ImportBankStatementRequest;
use App\Http\Requests\BankReconciliation\UploadBankStatementRequest;
use App\Models\BankStatement;
use App\Models\FinancialAccount;
use App\Services\BankReconciliation\ReconciliationService;
use App\Services\BankReconciliation\StatementImportService;
use App\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class BankStatementController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $accounts = $this->bankAccounts($request, $access);
        $statements = BankStatement::query()->with(['financialAccount', 'branch', 'uploader'])
            ->whereIn('financial_account_id', $accounts->pluck('id'))
            ->when($request->filled('financial_account_id'), fn ($query) => $query->where('financial_account_id', $request->integer('financial_account_id')))
            ->latest('id')->paginate(25)->withQueryString();

        return view('bank-reconciliation.statements.index', compact('accounts', 'statements'));
    }

    public function store(UploadBankStatementRequest $request, StatementImportService $service): RedirectResponse
    {
        $account = FinancialAccount::query()->with('branch')->findOrFail($request->integer('financial_account_id'));
        $statement = $service->upload($request->file('statement_file'), $account, $request->user());

        return redirect()->route('bank-statements.show', $statement)->with('status', 'Statement stored privately. Map its columns to import the lines.');
    }

    public function show(Request $request, BankStatement $bankStatement, ReconciliationService $reconciliations, StatementImportService $statements): View
    {
        $reconciliations->authorizeAccount($bankStatement->financialAccount, $request->user(), 'bank_reconciliation.view');
        $lines = $bankStatement->isImported() ? $bankStatement->lines()->paginate(50) : null;

        return view('bank-reconciliation.statements.show', [
            'statement' => $bankStatement->load(['financialAccount', 'branch', 'uploader']),
            'lines' => $lines,
            'dateFormats' => StatementImportService::DATE_FORMATS,
            'canDiscard' => $statements->canDiscard($bankStatement, $request->user()),
            'retained' => $statements->isRetained($bankStatement),
        ]);
    }

    public function import(ImportBankStatementRequest $request, BankStatement $bankStatement, StatementImportService $service): RedirectResponse
    {
        $statement = $service->import($bankStatement, $request->validated(), $request->user());

        return redirect()->route('bank-statements.show', $statement)->with('status', $statement->line_count.' statement lines imported.');
    }

    public function destroy(Request $request, BankStatement $bankStatement, StatementImportService $service): RedirectResponse
    {
        $service->discard($bankStatement, $request->user());

        return redirect()->route('bank-statements.index')->with('status', 'Statement removed and its stored file deleted.');
    }

    /** @return Collection<int, FinancialAccount> */
    private function bankAccounts(Request $request, BranchAccess $access): Collection
    {
        return FinancialAccount::query()->with('branch')->where('type', 'bank')->bookVisibleTo($request->user(), $access->scope($request->user())->select('id'))->orderBy('name')->get();
    }
}
