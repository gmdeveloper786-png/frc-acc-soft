<?php

namespace App\Http\Controllers\BankReconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankReconciliation\RejectBankReconciliationRequest;
use App\Http\Requests\BankReconciliation\StoreBankReconciliationRequest;
use App\Models\AuditLog;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\FinancialAccount;
use App\Services\BankReconciliation\ReconciliationCalculator;
use App\Services\BankReconciliation\ReconciliationReport;
use App\Services\BankReconciliation\ReconciliationService;
use App\Services\BranchAccess;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankReconciliationController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $accounts = $this->bankAccounts($request, $access);
        $latest = BankReconciliation::query()->whereIn('financial_account_id', $accounts->pluck('id'))
            ->selectRaw("financial_account_id, MAX(CASE WHEN status = 'approved' THEN statement_date END) as last_approved, MAX(CASE WHEN open_key IS NOT NULL THEN id END) as open_id")
            ->groupBy('financial_account_id')->get()->keyBy('financial_account_id');
        $reconciliations = BankReconciliation::query()->with(['financialAccount', 'branch', 'preparer', 'approver'])
            ->whereIn('financial_account_id', $accounts->pluck('id'))
            ->when($request->filled('financial_account_id'), fn ($query) => $query->where('financial_account_id', $request->integer('financial_account_id')))
            ->when(in_array($request->query('status'), BankReconciliation::STATUSES, true), fn ($query) => $query->where('status', $request->query('status')))
            ->orderByDesc('statement_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('bank-reconciliation.index', compact('accounts', 'latest', 'reconciliations'));
    }

    public function create(Request $request, BranchAccess $access): View
    {
        $accounts = $this->bankAccounts($request, $access);
        $statements = BankStatement::query()->whereIn('financial_account_id', $accounts->pluck('id'))->where('status', BankStatement::IMPORTED)->orderByDesc('period_to')->get();

        return view('bank-reconciliation.create', compact('accounts', 'statements'));
    }

    public function store(StoreBankReconciliationRequest $request, ReconciliationService $service): RedirectResponse
    {
        $account = FinancialAccount::query()->with('branch')->findOrFail($request->integer('financial_account_id'));
        $reconciliation = $service->create($account, $request->validated(), $request->user());

        return redirect()->route('bank-reconciliations.show', $reconciliation)->with('status', 'Reconciliation started. Match the statement lines to the bank book.');
    }

    public function show(Request $request, BankReconciliation $bankReconciliation, ReconciliationService $service, ReconciliationCalculator $calculator): View
    {
        $service->authorizeAccount($bankReconciliation->financialAccount, $request->user(), 'bank_reconciliation.view');
        $bankReconciliation->load(['financialAccount.branch', 'statement', 'preparer', 'submitter', 'approver', 'rejecter']);
        $figures = $calculator->calculate($bankReconciliation);
        $editable = $bankReconciliation->isDraft() && $request->user()->can('bank_reconciliation.prepare');
        $requireReference = $request->boolean('same_reference');
        $cancellation = $bankReconciliation->isCancelled()
            ? AuditLog::query()->where('auditable_type', $bankReconciliation->getMorphClass())->where('auditable_id', $bankReconciliation->id)
                ->where('event', 'bank_reconciliation.cancelled')->with('actor')->latest('id')->first()
            : null;

        return view('bank-reconciliation.show', [
            'reconciliation' => $bankReconciliation,
            'figures' => $figures,
            'editable' => $editable,
            'requireReference' => $requireReference,
            'suggestions' => $editable ? $calculator->suggestions($bankReconciliation, $requireReference) : [],
            'bookCandidates' => $editable ? $calculator->outstandingBookLines($bankReconciliation) : collect(),
            'firstStatementDate' => BankStatement::query()->where('financial_account_id', $bankReconciliation->financial_account_id)->where('status', BankStatement::IMPORTED)->min('period_from'),
            'isMaker' => in_array($request->user()->id, $service->makerIds($bankReconciliation), true),
            'formula' => ReconciliationReport::FORMULA,
            'cancellation' => $cancellation,
        ]);
    }

    public function cancel(Request $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $service->cancel($bankReconciliation, trim($data['cancellation_reason']), $request->user());

        return redirect()->route('bank-reconciliations.index')->with('status', 'Draft reconciliation cancelled. Its matches were released and a new reconciliation can be started.');
    }

    public function statement(Request $request, BankReconciliation $bankReconciliation, ReconciliationService $service, ReconciliationCalculator $calculator): View
    {
        $service->authorizeAccount($bankReconciliation->financialAccount, $request->user(), 'bank_reconciliation.view');
        $bankReconciliation->load(['financialAccount.branch', 'preparer', 'submitter', 'approver']);

        return view('bank-reconciliation.statement', [
            'reconciliation' => $bankReconciliation,
            'figures' => $calculator->calculate($bankReconciliation),
            'formula' => ReconciliationReport::FORMULA,
        ]);
    }

    public function export(Request $request, BankReconciliation $bankReconciliation, string $format, ReconciliationService $service, ReconciliationReport $report, ReportExporter $exporter): StreamedResponse
    {
        $service->authorizeAccount($bankReconciliation->financialAccount, $request->user(), 'bank_reconciliation.view');
        abort_unless($request->user()->can('finance_reports.export'), 403);
        $bankReconciliation->load('financialAccount.branch');

        return $exporter->download($report->table($bankReconciliation), $format, 'bank-reconciliation-'.$bankReconciliation->statement_date->toDateString());
    }

    public function submit(Request $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $service->submit($bankReconciliation, $request->user());

        return redirect()->route('bank-reconciliations.show', $bankReconciliation)->with('status', 'Reconciliation submitted for approval.');
    }

    public function approve(Request $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $service->approve($bankReconciliation, $request->user());

        return redirect()->route('bank-reconciliations.show', $bankReconciliation)->with('status', 'Reconciliation approved and locked.');
    }

    public function reject(RejectBankReconciliationRequest $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $service->reject($bankReconciliation, $request->validated('reason'), $request->user());

        return redirect()->route('bank-reconciliations.show', $bankReconciliation)->with('status', 'Reconciliation returned to the preparer.');
    }

    /** @return Collection<int, FinancialAccount> */
    private function bankAccounts(Request $request, BranchAccess $access): Collection
    {
        return FinancialAccount::query()->with('branch')->where('type', 'bank')->bookVisibleTo($request->user(), $access->scope($request->user())->select('id'))->orderBy('name')->get();
    }
}
