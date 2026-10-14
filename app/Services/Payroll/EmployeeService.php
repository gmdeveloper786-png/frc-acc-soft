<?php

namespace App\Services\Payroll;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Employee master data. Bank details and salary structures are only written
 * or released for users holding payroll.view_sensitive, and are never audited.
 */
class EmployeeService
{
    public function __construct(private BranchAccess $branchAccess, private AuditLogger $audit) {}

    /** Whether the user may see salary amounts, salary structures and bank details. */
    public function canViewSensitive(User $user): bool
    {
        return $user->is_active && $user->can('payroll.view_sensitive');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Employee
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor): Employee {
            $branch = $this->guardBranch((int) $data['branch_id'], $actor);
            $employee = Employee::query()->create([...$this->attributes($data, $actor), 'branch_id' => $branch->id, 'created_by' => $actor->id]);
            $this->audit->log('employees.created', $employee, ['employee_code' => $employee->employee_code, 'branch_id' => $branch->id]);

            return $employee;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data, User $actor): Employee
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($employee, $data, $actor): Employee {
            $employee = $this->lock($employee, $actor);
            $branch = $this->guardBranch((int) $data['branch_id'], $actor);
            $employee->fill([...$this->attributes($data, $actor), 'branch_id' => $branch->id]);
            $changed = array_values(array_diff(array_keys($employee->getDirty()), ['bank_details']));
            $bankChanged = $employee->isDirty('bank_details');
            $employee->save();
            $this->audit->log('employees.updated', $employee, ['employee_code' => $employee->employee_code, 'changed' => $changed, 'bank_details_changed' => $bankChanged]);

            return $employee;
        });
    }

    /**
     * Replace the salary structure. Amounts are entered by the user; nothing
     * is calculated here.
     *
     * @param  array<int|string, mixed>  $amounts  component id => amount (blank or zero removes the item)
     */
    public function saveSalaryStructure(Employee $employee, array $amounts, User $actor): Employee
    {
        $this->authorize($actor);
        abort_unless($this->canViewSensitive($actor), 403);

        return DB::transaction(function () use ($employee, $amounts, $actor): Employee {
            $employee = $this->lock($employee, $actor);
            $components = PayrollComponent::query()->whereKey(array_keys($amounts))->get()->keyBy('id');
            foreach ($amounts as $componentId => $value) {
                $component = $components->get((int) $componentId);
                if (! $component) {
                    throw ValidationException::withMessages(["amounts.{$componentId}" => 'The selected payroll component does not exist.']);
                }
                try {
                    $amount = AccountingDecimal::of(blank($value) ? '0' : $value);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["amounts.{$componentId}" => "{$component->name}: enter a valid amount with at most four decimals."]);
                }
                if ($amount->isNegative()) {
                    throw ValidationException::withMessages(["amounts.{$componentId}" => "{$component->name}: amounts cannot be negative."]);
                }
                $existing = $employee->salaryItems()->where('payroll_component_id', $component->id)->first();
                if ($amount->isZero()) {
                    $existing?->delete();

                    continue;
                }
                if (! $component->is_active && ! $existing) {
                    throw ValidationException::withMessages(["amounts.{$componentId}" => "{$component->name} is inactive and cannot be added."]);
                }
                $employee->salaryItems()->updateOrCreate(['payroll_component_id' => $component->id], ['amount' => AccountingDecimal::string($amount)]);
            }
            $this->audit->log('employees.salary_structure_updated', $employee, ['employee_code' => $employee->employee_code, 'component_count' => $employee->salaryItems()->count()]);

            return $employee->load('salaryItems.component');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, User $actor): array
    {
        $attributes = [
            'employee_code' => trim((string) $data['employee_code']), 'name' => trim((string) $data['name']),
            'designation' => $data['designation'] ?? null, 'employment_status' => $data['employment_status'],
            'joined_on' => $data['joined_on'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
        // Bank details are only accepted from users who are allowed to read them back.
        if (array_key_exists('bank_details', $data) && $this->canViewSensitive($actor)) {
            $attributes['bank_details'] = filled($data['bank_details']) ? (string) $data['bank_details'] : null;
        }

        return $attributes;
    }

    private function guardBranch(int $branchId, User $actor): Branch
    {
        $branch = Branch::query()->findOrFail($branchId);
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);

        return $branch;
    }

    private function lock(Employee $employee, User $actor): Employee
    {
        $employee = Employee::query()->with('branch')->lockForUpdate()->findOrFail($employee->id);
        abort_unless($this->branchAccess->canAccess($actor, $employee->branch), 403);

        return $employee;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('employees.manage'), 403);
    }
}
