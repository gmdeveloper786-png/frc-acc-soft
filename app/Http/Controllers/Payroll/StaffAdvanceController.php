<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\AccountingPeriodStatus;
use App\Enums\EmploymentStatus;
use App\Enums\StaffAdvanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveStaffAdvanceRequest;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\StaffAdvance;
use App\Services\BranchAccess;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollRunService;
use App\Services\Payroll\StaffAdvanceRecoveryService;
use App\Services\Payroll\StaffAdvanceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff advance and loan register. Amounts are shown only to users holding
 * payroll.view_sensitive; nothing here posts a journal or creates a payment.
 */
class StaffAdvanceController extends Controller
{
    /** Columns that never carry an amount. */
    private const LIST_COLUMNS = ['id', 'reference', 'employee_id', 'branch_id', 'type', 'first_recovery_period_id', 'payroll_component_id', 'status', 'disbursement_transaction_id', 'created_by', 'approved_by', 'created_at', 'approved_at', 'disbursed_at', 'settled_at'];

    public function index(Request $request, BranchAccess $access, EmployeeService $employees): View
    {
        $sensitive = $employees->canViewSensitive($request->user());
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $advances = StaffAdvance::query()->select($sensitive ? ['*'] : self::LIST_COLUMNS)->with(['employee:id,employee_code,name', 'branch:id,name', 'firstRecoveryPeriod:id,name', ...($sensitive ? ['instalments'] : [])])
            ->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when(StaffAdvanceStatus::tryFrom($request->string('status')->toString()), fn ($query, StaffAdvanceStatus $status) => $query->where('status', $status->value))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('payroll.advances.index', [
            'advances' => $advances, 'branches' => $branches, 'sensitive' => $sensitive,
            'employees' => Employee::query()->select(['id', 'employee_code', 'name'])->whereIn('branch_id', $branches->pluck('id'))->orderBy('employee_code')->get(),
        ]);
    }

    public function create(Request $request, BranchAccess $access, StaffAdvanceService $service): View
    {
        return view('payroll.advances.create', [...$this->formData($request, $access, $service), 'advance' => new StaffAdvance]);
    }

    public function store(SaveStaffAdvanceRequest $request, StaffAdvanceService $service): RedirectResponse
    {
        $advance = $service->create($request->validated(), $request->user());

        return redirect()->route('staff-advances.show', $advance)->with('status', "Draft {$advance->reference} created with its recovery schedule. Review it, then submit for approval.");
    }

    public function show(Request $request, StaffAdvance $staffAdvance, BranchAccess $access, EmployeeService $employees, StaffAdvanceService $service, StaffAdvanceRecoveryService $recovery, PayrollRunService $runs): View
    {
        $this->guardBranch($request, $staffAdvance, $access);
        $user = $request->user();
        $sensitive = $employees->canViewSensitive($user);
        if ($sensitive) {
            $recovery->syncAdvance($staffAdvance);
        }
        $advance = StaffAdvance::query()->select($sensitive ? ['*'] : [...self::LIST_COLUMNS, 'purpose', 'notes', 'rejection_reason', 'cancellation_reason', 'submitted_by', 'submitted_at', 'cancelled_at'])
            ->with(['employee:id,employee_code,name,designation', 'branch', 'firstRecoveryPeriod', 'component', 'creator', 'submitter', 'approver', 'history.actor', 'disbursement'])
            ->findOrFail($staffAdvance->id);
        if ($sensitive) {
            $advance->load(['instalments.period', 'instalments.payrollRunLine.run']);
        }

        return view('payroll.advances.show', [
            'advance' => $advance, 'sensitive' => $sensitive, 'policyApproved' => $runs->policyApproved(),
            'canApprove' => $service->canApprove($advance, $user),
            'disbursements' => $sensitive && $advance->status === StaffAdvanceStatus::Approved && $user->can('staff_advances.manage') ? $service->eligibleDisbursements($advance) : collect(),
        ]);
    }

    public function edit(Request $request, StaffAdvance $staffAdvance, BranchAccess $access, StaffAdvanceService $service): View
    {
        $this->guardBranch($request, $staffAdvance, $access);
        abort_unless($staffAdvance->status->isEditable(), 403, 'Only a draft staff advance can be edited.');

        return view('payroll.advances.edit', [...$this->formData($request, $access, $service), 'advance' => $staffAdvance]);
    }

    public function update(SaveStaffAdvanceRequest $request, StaffAdvance $staffAdvance, StaffAdvanceService $service): RedirectResponse
    {
        $service->update($staffAdvance, $request->validated(), $request->user());

        return redirect()->route('staff-advances.show', $staffAdvance)->with('status', 'Draft updated and its recovery schedule regenerated.');
    }

    public function submit(Request $request, StaffAdvance $staffAdvance, StaffAdvanceService $service): RedirectResponse
    {
        $service->submit($staffAdvance, $request->user());

        return back()->with('status', 'Staff advance submitted for approval.');
    }

    public function approve(Request $request, StaffAdvance $staffAdvance, StaffAdvanceService $service): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);
        $service->approve($staffAdvance, $request->user(), $data['comments'] ?? null);

        return back()->with('status', 'Staff advance approved. Pay it out through the payment workflow, then link the posted payment here.');
    }

    public function reject(Request $request, StaffAdvance $staffAdvance, StaffAdvanceService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($staffAdvance, $data['reason'], $request->user());

        return back()->with('status', 'Staff advance returned to its preparer as a draft.');
    }

    public function cancel(Request $request, StaffAdvance $staffAdvance, StaffAdvanceService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->cancel($staffAdvance, $data['reason'], $request->user());

        return back()->with('status', 'Staff advance cancelled.');
    }

    public function linkDisbursement(Request $request, StaffAdvance $staffAdvance, StaffAdvanceService $service): RedirectResponse
    {
        $data = $request->validate(['operational_transaction_id' => ['required', 'integer']]);
        $service->linkDisbursement($staffAdvance, (int) $data['operational_transaction_id'], $request->user());

        return back()->with('status', 'Disbursement linked. The advance is active and is recovered through posted payroll runs.');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, BranchAccess $access, StaffAdvanceService $service): array
    {
        $branchIds = $access->scope($request->user())->pluck('id');

        return [
            'employees' => Employee::query()->select(['id', 'employee_code', 'name', 'branch_id'])->with('branch:id,name')->whereIn('branch_id', $branchIds)
                ->where('employment_status', EmploymentStatus::Active->value)->orderBy('employee_code')->get(),
            'components' => $service->eligibleComponents(),
            'periods' => $this->openPeriods(),
            'policyApproved' => app(PayrollRunService::class)->policyApproved(),
        ];
    }

    /** @return Collection<int, AccountingPeriod> */
    private function openPeriods(): Collection
    {
        return AccountingPeriod::query()->where('status', AccountingPeriodStatus::Open->value)->orderBy('start_date')->get();
    }

    private function guardBranch(Request $request, StaffAdvance $advance, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $advance->branch), 403);
    }
}
