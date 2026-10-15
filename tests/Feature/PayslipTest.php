<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\AccountingPeriod;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Payslip Branch']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'name' => 'January 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->maker = payslipUser(SystemRole::Accountant, $this->branch);
    $this->approver = payslipUser(SystemRole::Approver, $this->branch);

    $this->first = payslipEmployee($this, 'EMP-PS1', 'Payslip First', ['BASIC' => '41234.5600', 'ALLOWANCE' => '5000.0000', 'TAX' => '1234.5600']);
    $this->second = payslipEmployee($this, 'EMP-PS2', 'Payslip Second', ['BASIC' => '30000.0000']);
    $this->run = payslipRun($this);
    $this->actingAs($this->maker);
});

function payslipUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** @param array<string, string> $amounts component code => amount */
function payslipEmployee(object $test, string $code, string $name, array $amounts): Employee
{
    $employee = Employee::factory()->create(['branch_id' => $test->branch->id, 'created_by' => $test->maker->id, 'employee_code' => $code, 'name' => $name, 'designation' => 'Therapist']);
    foreach ($amounts as $component => $amount) {
        $employee->salaryItems()->create(['payroll_component_id' => PayrollComponent::query()->where('code', $component)->value('id'), 'amount' => $amount]);
    }

    return $employee;
}

/** A run of the test branch, taken through validation and optionally approval. */
function payslipRun(object $test, bool $approve = true): PayrollRun
{
    $service = app(PayrollRunService::class);
    $run = $service->validate($service->createFromStructures(['branch_id' => $test->branch->id, 'accounting_period_id' => $test->period->id], $test->maker), $test->maker);
    if (! $approve) {
        return $run;
    }
    $service->submit($run, $test->maker);

    return $service->approve($run->fresh(), $test->approver);
}

test('a payslip shows the organisation, period, employee, components and totals of an approved run', function () {
    $line = $this->run->lines()->where('employee_id', $this->first->id)->firstOrFail();
    $organisation = AppSetting::query()->where('key', 'organization_name')->value('value');

    $this->get(route('payroll-runs.payslip', [$this->run, $line]))->assertOk()
        ->assertSee($organisation)->assertSee('January 2026')->assertSee('01 Jan 2026')
        ->assertSee('EMP-PS1')->assertSee('Payslip First')->assertSee('Therapist')->assertSee('Payslip Branch')
        ->assertSeeInOrder(['Basic Salary', '41,234.56', 'Income Tax', '1,234.56'])
        ->assertSee('Allowance')->assertSee('5,000.00')
        ->assertSee('46,234.56')->assertSee('45,000.00')
        ->assertSee('Approved — not yet posted or paid')
        ->assertDontSee('Payslip Second');
});

test('print all payslips covers every employee of the run, one payslip each', function () {
    $response = $this->get(route('payroll-runs.payslips', $this->run))->assertOk()
        ->assertSeeInOrder(['EMP-PS1', '45,000.00', 'EMP-PS2', '30,000.00']);
    expect(substr_count($response->getContent(), 'class="surface-card payslip"'))->toBe(2);

    $this->get(route('payroll-runs.show', $this->run))->assertOk()
        ->assertSee(route('payroll-runs.payslips', $this->run))
        ->assertSee(route('payroll-runs.payslip', [$this->run, $this->run->lines()->first()]));
});

test('payslips need the sensitive payroll permission and access to the branch', function () {
    $line = $this->run->lines()->firstOrFail();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['payroll.view']);
    $viewer->branches()->attach($this->branch);
    $stranger = payslipUser(SystemRole::Accountant, $this->otherBranch);

    $this->actingAs($viewer)->get(route('payroll-runs.payslip', [$this->run, $line]))->assertForbidden();
    $this->actingAs($viewer)->get(route('payroll-runs.payslips', $this->run))->assertForbidden();
    $this->actingAs($viewer)->get(route('payroll-runs.show', $this->run))->assertOk()->assertDontSee('/payslip')->assertDontSee('41,234.56');

    $this->actingAs($stranger)->get(route('payroll-runs.payslip', [$this->run, $line]))->assertForbidden();
    $this->actingAs($stranger)->get(route('payroll-runs.payslips', $this->run))->assertForbidden();
});

test('payslips are refused for runs that are not approved and for a line of another run', function () {
    $this->run->update(['status' => 'rejected', 'active_key' => null]);
    $draft = payslipRun($this, false);

    $this->get(route('payroll-runs.payslips', $draft))->assertForbidden();
    $this->get(route('payroll-runs.payslip', [$draft, $draft->lines()->first()]))->assertForbidden();
    $this->get(route('payroll-runs.show', $draft))->assertOk()->assertDontSee('Print all payslips');
    $this->get(route('payroll-runs.payslip', [$this->run, $draft->lines()->first()]))->assertNotFound();
});
