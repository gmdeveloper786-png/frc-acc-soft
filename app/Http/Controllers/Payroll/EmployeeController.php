<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEmployeeRequest;
use App\Models\Employee;
use App\Models\PayrollComponent;
use App\Services\BranchAccess;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    /** The directory never loads salary amounts or bank details. */
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $employees = Employee::query()->select(['id', 'employee_code', 'name', 'branch_id', 'designation', 'employment_status', 'joined_on'])->with('branch')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when(EmploymentStatus::tryFrom($request->string('status')->toString()), fn ($query, EmploymentStatus $status) => $query->where('employment_status', $status->value))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('employee_code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')->orWhere('designation', 'like', '%'.$request->string('search').'%')))
            ->orderBy('employee_code')->paginate(20)->withQueryString();

        return view('payroll.employees.index', ['employees' => $employees, 'branches' => $branches]);
    }

    public function create(Request $request, BranchAccess $access, EmployeeService $service): View
    {
        return view('payroll.employees.create', ['branches' => $this->branches($request, $access), 'sensitive' => $service->canViewSensitive($request->user())]);
    }

    public function store(SaveEmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $employee = $service->create($request->validated(), $request->user());

        return redirect()->route('employees.show', $employee)->with('status', "Employee {$employee->employee_code} created.");
    }

    public function show(Request $request, Employee $employee, BranchAccess $access, EmployeeService $service, PayrollRunService $runs): View
    {
        $this->guardBranch($request, $employee, $access);
        $sensitive = $service->canViewSensitive($request->user());
        $employee->load('branch');
        $bankDetails = $sensitive ? $employee->bank_details : null;
        // Amount columns are not even read from the database for users without payroll.view_sensitive.
        $lineColumns = $sensitive ? ['*'] : ['id', 'payroll_run_id', 'employee_id'];
        $history = $request->user()->can('payroll.view')
            ? $employee->payrollLines()->select($lineColumns)->with('run.period')->latest('id')->limit(24)->get()
            : collect();
        if ($sensitive) {
            $employee->load('salaryItems.component');
        } else {
            $employee->offsetUnset('bank_details');
        }

        return view('payroll.employees.show', [
            'employee' => $employee, 'sensitive' => $sensitive, 'bankDetails' => $bankDetails, 'history' => $history,
            'components' => $sensitive ? PayrollComponent::query()->where('is_active', true)->orWhereIn('id', $employee->salaryItems->pluck('payroll_component_id'))->orderBy('sort_order')->orderBy('name')->get() : collect(),
            'policyApproved' => $runs->policyApproved(),
        ]);
    }

    public function edit(Request $request, Employee $employee, BranchAccess $access, EmployeeService $service): View
    {
        $this->guardBranch($request, $employee, $access);
        $sensitive = $service->canViewSensitive($request->user());
        $bankDetails = $sensitive ? $employee->bank_details : null;
        $employee->offsetUnset('bank_details');

        return view('payroll.employees.edit', ['employee' => $employee, 'branches' => $this->branches($request, $access), 'sensitive' => $sensitive, 'bankDetails' => $bankDetails]);
    }

    public function update(SaveEmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->update($employee, $request->validated(), $request->user());

        return redirect()->route('employees.show', $employee)->with('status', 'Employee updated.');
    }

    public function updateSalary(Request $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $data = $request->validate(['amounts' => ['required', 'array'], 'amounts.*' => ['nullable', 'decimal:0,4', 'gte:0']], [], ['amounts.*' => 'amount']);
        $service->saveSalaryStructure($employee, $data['amounts'], $request->user());

        return redirect()->route('employees.show', $employee)->with('status', 'Salary structure saved. It is used when a new draft payroll run is created.');
    }

    private function guardBranch(Request $request, Employee $employee, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $employee->branch), 403);
    }

    private function branches(Request $request, BranchAccess $access): Collection
    {
        return $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get();
    }
}
