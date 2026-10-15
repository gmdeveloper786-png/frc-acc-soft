<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\AccountingPeriodStatus;
use App\Enums\EmploymentStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PayrollRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePayrollRunRequest;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollRunService;
use App\Services\Payroll\PayslipService;
use App\Services\Payroll\StaffAdvanceRecoveryService;
use App\Services\Payroll\StaffAdvanceService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PayrollRunController extends Controller
{
    /** Columns that never carry a salary amount. */
    private const RUN_COLUMNS = ['id', 'branch_id', 'accounting_period_id', 'status', 'active_key', 'source', 'journal_entry_id', 'created_by', 'validated_by', 'submitted_by', 'approved_by', 'posted_by', 'validated_at', 'submitted_at', 'approved_at', 'posted_at', 'paid_at', 'reversed_at', 'rejection_reason', 'reversal_reason', 'created_at', 'updated_at'];

    public function index(Request $request, BranchAccess $access, EmployeeService $employees, PayrollRunService $service): View
    {
        $sensitive = $employees->canViewSensitive($request->user());
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $runs = PayrollRun::query()->select($sensitive ? ['*'] : self::RUN_COLUMNS)->with(['branch', 'period'])->withCount('lines')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('accounting_period_id'), fn ($query) => $query->where('accounting_period_id', $request->integer('accounting_period_id')))
            ->when(PayrollRunStatus::tryFrom($request->string('status')->toString()), fn ($query, PayrollRunStatus $status) => $query->where('status', $status->value))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('payroll.runs.index', [
            'runs' => $runs, 'branches' => $branches, 'sensitive' => $sensitive, 'policyApproved' => $service->policyApproved(),
            'periods' => AccountingPeriod::query()->orderByDesc('start_date')->get(),
        ]);
    }

    public function create(Request $request, BranchAccess $access, PayrollRunService $service): View
    {
        return view('payroll.runs.create', [
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'periods' => $this->openPeriods(), 'policyApproved' => $service->policyApproved(),
        ]);
    }

    public function store(StorePayrollRunRequest $request, PayrollRunService $service): RedirectResponse
    {
        $run = $service->createFromStructures($request->validated(), $request->user());

        return redirect()->route('payroll-runs.show', $run)->with('status', 'Draft payroll run created from the salary structures. Review the lines, then validate.');
    }

    public function show(Request $request, PayrollRun $payrollRun, BranchAccess $access, EmployeeService $employees, PayrollRunService $service, StaffAdvanceRecoveryService $advanceRecovery, PayslipService $payslips): View
    {
        $this->guardBranch($request, $payrollRun, $access);
        $run = $service->syncPaidStatus($payrollRun);
        $user = $request->user();
        $sensitive = $employees->canViewSensitive($user);

        return view('payroll.runs.show', [
            ...$this->detail($run, $sensitive, $service),
            'canApprove' => $service->canApprove($run, $user),
            'isMaker' => in_array($user->id, [$run->created_by, $run->validated_by, $run->submitted_by], true),
            'financialAccounts' => $user->can('payroll.pay') ? FinancialAccount::query()->where('branch_id', $run->branch_id)->where('is_active', true)->whereIn('type', ['bank', 'cash'])->orderBy('name')->get() : collect(),
            // Staff advance instalments due by this period, for the preparer to put on the draft lines.
            'dueRecoveries' => $sensitive && in_array($run->status, [PayrollRunStatus::Draft, PayrollRunStatus::Rejected, PayrollRunStatus::Validated], true) ? $advanceRecovery->dueForRun($run) : collect(),
            'payslipsAvailable' => $sensitive && $payslips->isIssuable($run),
            'availableEmployees' => $run->status->isEditable() && $user->can('payroll.manage')
                ? Employee::query()->select(['id', 'employee_code', 'name'])->where('branch_id', $run->branch_id)->where('employment_status', EmploymentStatus::Active->value)->whereNotIn('id', $run->lines->pluck('employee_id'))->orderBy('employee_code')->get()
                : collect(),
        ]);
    }

    /** Print-friendly payroll register. Amounts are its whole purpose, so it needs payroll.view_sensitive. */
    public function register(Request $request, PayrollRun $payrollRun, BranchAccess $access, EmployeeService $employees, PayrollRunService $service): View
    {
        $this->guardBranch($request, $payrollRun, $access);
        abort_unless($employees->canViewSensitive($request->user()), 403, 'The payroll register shows salary amounts and needs the sensitive payroll permission.');

        return view('payroll.runs.register', $this->detail($payrollRun, true, $service));
    }

    public function editLine(Request $request, PayrollRun $payrollRun, PayrollRunLine $line, BranchAccess $access, EmployeeService $employees): View
    {
        $this->guardBranch($request, $payrollRun, $access);
        abort_unless($line->payroll_run_id === $payrollRun->id, 404);
        abort_unless($employees->canViewSensitive($request->user()), 403);
        abort_unless($payrollRun->status->isEditable(), 403, 'Payroll lines can only change while the run is a draft.');
        $line->load(['employee', 'items.component']);

        return view('payroll.runs.edit-line', [
            'run' => $payrollRun->load(['branch', 'period']), 'line' => $line,
            'components' => PayrollComponent::query()->where('is_active', true)->orWhereIn('id', $line->items->pluck('payroll_component_id'))->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function updateLine(Request $request, PayrollRun $payrollRun, PayrollRunLine $line, PayrollRunService $service, EmployeeService $employees): RedirectResponse
    {
        abort_unless($line->payroll_run_id === $payrollRun->id, 404);
        abort_unless($employees->canViewSensitive($request->user()), 403);
        $data = $request->validate(['amounts' => ['required', 'array'], 'amounts.*' => ['nullable', 'decimal:0,4', 'gte:0']], [], ['amounts.*' => 'amount']);
        $service->updateLine($line, $data['amounts'], $request->user());

        return redirect()->route('payroll-runs.show', $payrollRun)->with('status', 'Payroll line updated. Validate the run again.');
    }

    public function addLine(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id']]);
        $service->addLine($payrollRun, (int) $data['employee_id'], $request->user());

        return back()->with('status', 'Employee added to the draft payroll run.');
    }

    public function removeLine(Request $request, PayrollRun $payrollRun, PayrollRunLine $line, PayrollRunService $service): RedirectResponse
    {
        abort_unless($line->payroll_run_id === $payrollRun->id, 404);
        $service->removeLine($line, $request->user());

        return back()->with('status', 'Employee removed from the draft payroll run.');
    }

    public function validateRun(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $run = $service->validate($payrollRun, $request->user());

        return $run->status === PayrollRunStatus::Validated
            ? back()->with('status', 'Payroll run validated. It can now be submitted for approval.')
            : back()->with('error', 'Validation found errors. Correct the highlighted lines and validate again.');
    }

    public function revert(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $service->revertToDraft($payrollRun, $request->user());

        return back()->with('status', 'Payroll run returned to draft.');
    }

    public function discard(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->discard($payrollRun, $data['reason'], $request->user());

        return back()->with('status', 'Payroll run discarded. A new run can be prepared for this branch and period.');
    }

    public function submit(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $service->submit($payrollRun, $request->user());

        return back()->with('status', 'Payroll run submitted for approval.');
    }

    public function approve(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);
        $service->approve($payrollRun, $request->user(), $data['comments'] ?? null);

        return back()->with('status', 'Payroll run approved. No accounting entry was posted.');
    }

    public function reject(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($payrollRun, $data['reason'], $request->user());

        return back()->with('status', 'Payroll run returned for correction.');
    }

    public function post(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $run = $service->post($payrollRun, $request->user());

        return back()->with('status', 'Salary accrual posted as voucher '.$run->journalEntry->voucher_number.'.');
    }

    public function pay(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bank_payment', 'cash_payment'])],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'transaction_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ]);
        $transaction = $service->pay($payrollRun, $data, $request->user());

        return back()->with('status', "Salary payment #{$transaction->id} created as a draft in the payment workflow. It must be submitted, approved, disbursed and posted there.");
    }

    /** Put the due staff advance recoveries on the draft lines, as an ordinary line edit by the preparer. */
    public function applyAdvanceRecoveries(Request $request, PayrollRun $payrollRun, StaffAdvanceService $advances): RedirectResponse
    {
        $changed = $advances->applyDueRecoveries($payrollRun, $request->user());

        return back()->with('status', $changed === 0 ? 'No payroll line needed a change: the due recoveries are already on the run.' : "Due advance recoveries entered on {$changed} payroll ".str('line')->plural($changed).'. Validate the run again.');
    }

    public function reverse(Request $request, PayrollRun $payrollRun, PayrollRunService $service): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reverse($payrollRun, $data['date'], $data['reason'], $request->user());

        return back()->with('status', 'Payroll accrual reversed.');
    }

    /**
     * Run detail for the screen and the register. Without the sensitive
     * permission no amount column is read from the database.
     *
     * @return array<string, mixed>
     */
    private function detail(PayrollRun $run, bool $sensitive, PayrollRunService $service): array
    {
        $run = PayrollRun::query()->select($sensitive ? ['*'] : self::RUN_COLUMNS)->with(['branch', 'period', 'creator', 'approver', 'journalEntry'])->findOrFail($run->id);
        $run->setRelation('lines', $run->lines()->select($sensitive ? ['*'] : ['id', 'payroll_run_id', 'employee_id', 'validation_errors'])
            ->with(['employee:id,employee_code,name,designation,employment_status', ...($sensitive ? ['items.component'] : [])])->get());
        $run->setRelation('history', $run->history()->select($sensitive ? ['*'] : ['id', 'payroll_run_id', 'action', 'from_status', 'to_status', 'actor_id', 'comments', 'created_at'])->with('actor')->get());
        $run->setRelation('payments', $run->payments()->with($sensitive ? 'transaction.financialAccount' : 'transaction:id,type,status,transaction_date,reference_number')->get());

        $components = collect();
        $componentTotals = [];
        if ($sensitive) {
            $items = $run->lines->flatMap->items;
            $components = $items->pluck('component')->unique('id')->sortBy([['sort_order', 'asc'], ['name', 'asc']])->values();
            foreach ($components as $component) {
                $componentTotals[$component->id] = AccountingDecimal::string($items->where('payroll_component_id', $component->id)
                    ->reduce(fn ($sum, $item) => $sum->plus(AccountingDecimal::of($item->amount)), BigDecimal::zero()->toScale(4)));
            }
        }

        return [
            'run' => $run, 'sensitive' => $sensitive, 'components' => $components, 'componentTotals' => $componentTotals,
            'policyApproved' => $service->policyApproved(),
            'paidAmount' => $sensitive && $run->status->isPosted() ? $service->paidAmount($run) : null,
            'outstandingAmount' => $sensitive && $run->status->isPosted() ? $service->outstandingAmount($run) : null,
        ];
    }

    private function guardBranch(Request $request, PayrollRun $run, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $run->branch), 403);
    }

    /** @return Collection<int, AccountingPeriod> */
    private function openPeriods(): Collection
    {
        return AccountingPeriod::query()->where('status', AccountingPeriodStatus::Open->value)
            ->whereHas('fiscalYear', fn ($query) => $query->where('status', FiscalYearStatus::Open->value))->orderByDesc('start_date')->get();
    }
}
