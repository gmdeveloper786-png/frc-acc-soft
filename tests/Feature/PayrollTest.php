<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\EmploymentStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\PayrollComponentType;
use App\Enums\PayrollRunStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\PayrollRunHistory;
use App\Models\PayrollRunPayment;
use App\Models\User;
use App\Services\OperationalTransactionService;
use App\Services\Payments\UnifiedPaymentService;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollComponentService;
use App\Services\Payroll\PayrollRunService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->maker = payrollUser(SystemRole::Accountant, $this->branch);
    $this->approver = payrollUser(SystemRole::Approver, $this->branch);
    $this->director = payrollUser(SystemRole::Director, $this->branch);
    $this->administrator = payrollUser(SystemRole::Administrator, $this->branch);

    $this->salaryExpense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->salaryPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->taxPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->basic = PayrollComponent::query()->where('code', 'BASIC')->firstOrFail();
    $this->allowance = PayrollComponent::query()->where('code', 'ALLOWANCE')->firstOrFail();
    $this->tax = PayrollComponent::query()->where('code', 'TAX')->firstOrFail();
    $this->actingAs($this->maker);
});

function payrollUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** An active employee of the test branch with the given salary structure (component code => amount). */
function payrollEmployee(object $test, array $amounts = ['BASIC' => '50000.0000'], array $overrides = []): Employee
{
    $employee = Employee::factory()->create(['branch_id' => $test->branch->id, 'created_by' => $test->maker->id, ...$overrides]);
    foreach ($amounts as $code => $amount) {
        $employee->salaryItems()->create(['payroll_component_id' => PayrollComponent::query()->where('code', $code)->value('id'), 'amount' => $amount]);
    }

    return $employee;
}

function payrollConfigure(object $test, bool $policy = true, bool $mappings = true, bool $taxAccount = true): void
{
    if ($policy) {
        AppSetting::query()->where('key', 'payroll.calculation_policy_approved')->first()->update(['value' => '1']);
    }
    if ($mappings) {
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryExpense->value, 'account_id' => $test->salaryExpense->id, 'updated_by' => $test->administrator->id]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryPayable->value, 'account_id' => $test->salaryPayable->id, 'updated_by' => $test->administrator->id]);
    }
    if ($taxAccount) {
        $test->tax->update(['account_id' => $test->taxPayable->id]);
    }
}

function payrollDraft(object $test): PayrollRun
{
    return app(PayrollRunService::class)->createFromStructures(['branch_id' => $test->branch->id, 'accounting_period_id' => $test->period->id], $test->maker);
}

function payrollApproved(object $test): PayrollRun
{
    $service = app(PayrollRunService::class);
    $run = $service->validate(payrollDraft($test), $test->maker);
    $service->submit($run, $test->maker);

    return $service->approve($run->fresh(), $test->approver);
}

function payrollPosted(object $test): PayrollRun
{
    payrollConfigure($test);

    return app(PayrollRunService::class)->post(payrollApproved($test), $test->maker);
}

/** Take a salary payment through the normal payment workflow up to posting. */
function payrollSettle(object $test, OperationalTransaction $payment, ?User $approver = null): OperationalTransaction
{
    $engine = app(OperationalTransactionService::class);
    $engine->submit($payment, $test->maker);
    $engine->approve($payment->fresh(), $approver ?? $test->approver);
    $engine->disburse($payment->fresh(), $test->maker);

    return $engine->post($payment->fresh(), $test->maker);
}

function payrollPayData(object $test, string $amount): array
{
    return ['type' => 'bank_payment', 'financial_account_id' => $test->bank->id, 'amount' => $amount, 'transaction_date' => '2026-01-31'];
}

// --- Employee security --------------------------------------------------------

test('employee directory and record hide every amount without the sensitive permission', function () {
    $employee = payrollEmployee($this, ['BASIC' => '54321.1200'], ['bank_details' => 'TESTBANK 000111222']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['employees.view', 'payroll.view']);
    $viewer->branches()->attach($this->branch);
    payrollDraft($this);

    $this->actingAs($viewer)->get(route('employees.index'))->assertOk()->assertSee($employee->employee_code)->assertDontSee('54,321.12');
    $this->actingAs($viewer)->get(route('employees.show', $employee))->assertOk()
        ->assertSee($employee->name)->assertSee('Not available to your role')
        ->assertDontSee('54,321.12')->assertDontSee('54321.12')->assertDontSee('TESTBANK');
    $this->actingAs($viewer)->get(route('payroll-runs.index'))->assertOk()->assertDontSee('54,321.12');
    $this->actingAs($viewer)->get(route('payroll-runs.show', PayrollRun::query()->first()))->assertOk()->assertSee($employee->employee_code)->assertDontSee('54,321.12')->assertDontSee('54321.12');
    $this->actingAs($viewer)->get(route('payroll-runs.register', PayrollRun::query()->first()))->assertForbidden();

    $this->actingAs($this->approver)->get(route('employees.show', $employee))->assertOk()->assertSee('54,321.12')->assertSee('TESTBANK 000111222');
});

test('bank details are encrypted at rest and never accepted from a user who cannot read them', function () {
    $employee = payrollEmployee($this, [], ['bank_details' => 'TESTBANK 000111222']);
    expect($employee->getRawOriginal('bank_details'))->not->toContain('TESTBANK')
        ->and($employee->toArray())->not->toHaveKey('bank_details');

    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['employees.view', 'employees.manage']);
    $clerk->branches()->attach($this->branch);
    $payload = ['employee_code' => $employee->employee_code, 'name' => 'Renamed Person', 'branch_id' => $this->branch->id, 'employment_status' => 'active', 'bank_details' => 'OVERWRITTEN'];
    $this->actingAs($clerk)->put(route('employees.update', $employee), $payload)->assertRedirect();

    expect($employee->fresh()->name)->toBe('Renamed Person')
        ->and($employee->fresh()->bank_details)->toBe('TESTBANK 000111222')
        ->and(fn () => app(EmployeeService::class)->saveSalaryStructure($employee, [$this->basic->id => '10.0000'], $clerk))->toThrow(HttpException::class);
    $this->actingAs($clerk)->put(route('employees.salary.update', $employee), ['amounts' => [$this->basic->id => '10.0000']])->assertForbidden();
});

test('employees and payroll runs of another branch are invisible and forbidden', function () {
    $foreignMaker = payrollUser(SystemRole::Accountant, $this->otherBranch);
    $foreign = Employee::factory()->create(['branch_id' => $this->otherBranch->id, 'created_by' => $foreignMaker->id]);
    $foreign->salaryItems()->create(['payroll_component_id' => $this->basic->id, 'amount' => '1000.0000']);
    $foreignRun = app(PayrollRunService::class)->createFromStructures(['branch_id' => $this->otherBranch->id, 'accounting_period_id' => $this->period->id], $foreignMaker);
    $own = payrollEmployee($this);

    $this->get(route('employees.index'))->assertOk()->assertSee($own->employee_code)->assertDontSee($foreign->employee_code);
    $this->get(route('employees.show', $foreign))->assertForbidden();
    $this->get(route('employees.edit', $foreign))->assertForbidden();
    $this->put(route('employees.update', $foreign), ['employee_code' => $foreign->employee_code, 'name' => 'X', 'branch_id' => $this->otherBranch->id, 'employment_status' => 'active'])->assertForbidden();
    $this->get(route('payroll-runs.index'))->assertOk()->assertDontSee($this->otherBranch->name);
    $this->get(route('payroll-runs.show', $foreignRun))->assertForbidden();
    $this->get(route('payroll-runs.register', $foreignRun))->assertForbidden();
    $this->post(route('payroll-runs.validate', $foreignRun))->assertForbidden();

    expect(fn () => app(PayrollRunService::class)->validate($foreignRun, $this->maker))->toThrow(HttpException::class)
        ->and(fn () => app(PayrollRunService::class)->createFromStructures(['branch_id' => $this->otherBranch->id, 'accounting_period_id' => $this->period->id], $this->maker))->toThrow(HttpException::class);
});

test('unauthorized roles cannot use payroll', function () {
    $employee = payrollEmployee($this);
    $run = payrollDraft($this);
    $cashier = payrollUser(SystemRole::Cashier, $this->branch);
    $service = app(PayrollRunService::class);

    $this->actingAs($cashier)->get(route('employees.index'))->assertForbidden();
    $this->actingAs($cashier)->get(route('payroll-runs.index'))->assertForbidden();
    $this->actingAs($cashier)->get(route('payroll-runs.show', $run))->assertForbidden();
    $this->actingAs($cashier)->get(route('payroll-components.index'))->assertForbidden();
    $this->actingAs($cashier)->post(route('payroll-runs.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id])->assertForbidden();
    $this->actingAs($this->approver)->post(route('payroll-runs.validate', $run))->assertForbidden();
    $this->actingAs($this->approver)->get(route('employees.create'))->assertForbidden();

    expect(fn () => $service->validate($run, $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $service->submit($run, $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($run, $this->maker))->toThrow(HttpException::class)
        ->and(fn () => $service->post($run, $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $service->pay($run, payrollPayData($this, '1.0000'), $this->approver))->toThrow(HttpException::class)
        ->and(fn () => app(EmployeeService::class)->update($employee, ['employee_code' => 'X1', 'name' => 'X', 'branch_id' => $this->branch->id, 'employment_status' => 'active'], $this->approver))->toThrow(HttpException::class)
        ->and(fn () => app(PayrollComponentService::class)->create(['code' => 'NEW', 'name' => 'New', 'type' => 'earning', 'category' => 'allowance'], $this->approver))->toThrow(HttpException::class);

    $this->maker->update(['is_active' => false]);
    expect(fn () => $service->validate($run, $this->maker->fresh()))->toThrow(HttpException::class);
});

// --- Calculation ---------------------------------------------------------------

test('gross deductions and net are exact with four decimal values', function () {
    $employee = payrollEmployee($this, ['BASIC' => '50000.1234', 'ALLOWANCE' => '2500.0001', 'TAX' => '1250.0005']);
    payrollEmployee($this, ['BASIC' => '0.1000', 'ALLOWANCE' => '0.2000', 'TAX' => '0.0001']);
    $run = app(PayrollRunService::class)->validate(payrollDraft($this), $this->maker);
    $first = $run->lines()->where('employee_id', $employee->id)->first();

    expect($run->status)->toBe(PayrollRunStatus::Validated)
        ->and($first->gross_amount)->toBe('52500.1235')
        ->and($first->total_deductions)->toBe('1250.0005')
        ->and($first->net_amount)->toBe('51250.1230')
        ->and($run->gross_amount)->toBe('52500.4235')
        ->and($run->total_deductions)->toBe('1250.0006')
        ->and($run->net_amount)->toBe('51250.4229')
        ->and($run->validated_by)->toBe($this->maker->id);
});

test('negative net pay is recorded as a line error and the run cannot be validated or submitted', function () {
    $overdrawn = payrollEmployee($this, ['BASIC' => '1000.0000', 'TAX' => '1000.0001']);
    $healthy = payrollEmployee($this, ['BASIC' => '2000.0000']);
    $service = app(PayrollRunService::class);
    $run = $service->validate(payrollDraft($this), $this->maker);
    $line = $run->lines()->where('employee_id', $overdrawn->id)->first();

    expect($run->status)->toBe(PayrollRunStatus::Draft)
        ->and($line->validation_errors)->toContain('Net pay is negative: deductions exceed earnings.')
        ->and($run->lines()->where('employee_id', $healthy->id)->first()->validation_errors)->toBeNull()
        ->and(fn () => $service->submit($run, $this->maker))->toThrow(ValidationException::class);

    expect(fn () => $service->updateLine($line, [$this->tax->id => '-5'], $this->maker))->toThrow(ValidationException::class);
    $service->updateLine($line, [$this->tax->id => '999.9999'], $this->maker);
    expect($service->validate($run->fresh(), $this->maker)->status)->toBe(PayrollRunStatus::Validated)
        ->and($line->fresh()->net_amount)->toBe('0.0001');
});

test('validation flags inactive employees, other-branch employees and inactive components', function () {
    $inactive = payrollEmployee($this);
    $moved = payrollEmployee($this, ['BASIC' => '100.0000', 'ALLOWANCE' => '5.0000']);
    $run = payrollDraft($this);
    $inactive->update(['employment_status' => EmploymentStatus::Terminated]);
    $moved->update(['branch_id' => $this->otherBranch->id]);
    $this->allowance->update(['is_active' => false]);

    $run = app(PayrollRunService::class)->validate($run, $this->maker);
    $errors = $run->lines()->get()->keyBy('employee_id');

    expect($run->status)->toBe(PayrollRunStatus::Draft)
        ->and($errors[$inactive->id]->validation_errors)->toContain('The employee is not active.')
        ->and($errors[$moved->id]->validation_errors)->toContain('The employee does not belong to the branch of this run.')
        ->and(collect($errors[$moved->id]->validation_errors)->contains(fn (string $error) => str_contains($error, 'inactive component')))->toBeTrue();
});

// --- Workflow -------------------------------------------------------------------

test('maker checker: creator validator and submitter cannot approve or reject', function () {
    payrollEmployee($this);
    $service = app(PayrollRunService::class);
    $submitter = payrollUser(SystemRole::Administrator, $this->branch);
    $run = $service->validate(payrollDraft($this), $this->administrator);
    $service->submit($run, $submitter);
    $creatorWithApproval = $this->maker;
    $creatorWithApproval->givePermissionTo('payroll.approve');

    foreach ([$creatorWithApproval, $this->administrator, $submitter] as $maker) {
        expect($service->canApprove($run->fresh(), $maker))->toBeFalse()
            ->and(fn () => $service->approve($run->fresh(), $maker))->toThrow(ValidationException::class)
            ->and(fn () => $service->reject($run->fresh(), 'Not acceptable', $maker))->toThrow(ValidationException::class);
    }
    $this->actingAs($submitter)->post(route('payroll-runs.approve', $run))->assertSessionHasErrors('approval');

    $approved = $service->approve($run->fresh(), $this->approver, 'Checked against the register');
    expect($approved->status)->toBe(PayrollRunStatus::Approved)
        ->and($approved->approved_by)->toBe($this->approver->id)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(PayrollRunHistory::query()->where('payroll_run_id', $run->id)->pluck('action')->all())->toBe(['created', 'validated', 'submitted', 'approved'])
        ->and(AuditLog::query()->where('event', 'payroll.approved')->exists())->toBeTrue();
});

test('rejected run frees the period and can be corrected and resubmitted', function () {
    payrollEmployee($this);
    $service = app(PayrollRunService::class);
    $run = $service->validate(payrollDraft($this), $this->maker);
    $service->submit($run, $this->maker);
    $rejected = $service->reject($run->fresh(), 'Tax amount is missing', $this->approver);

    expect($rejected->status)->toBe(PayrollRunStatus::Rejected)->and($rejected->active_key)->toBeNull();
    $service->updateLine($rejected->lines()->first(), [$this->tax->id => '100.0000'], $this->maker);
    $revalidated = $service->validate($rejected->fresh(), $this->maker);
    expect($revalidated->status)->toBe(PayrollRunStatus::Validated)
        ->and($revalidated->active_key)->toBe('1')
        ->and($revalidated->net_amount)->toBe('49900.0000');
});

test('duplicate payroll for the same branch and period is prevented by the service and the database', function () {
    payrollEmployee($this);
    $first = payrollDraft($this);

    expect(fn () => payrollDraft($this))->toThrow(ValidationException::class, 'already exists')
        ->and(fn () => PayrollRun::factory()->create(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id, 'created_by' => $this->maker->id]))->toThrow(UniqueConstraintViolationException::class);

    // A discarded run no longer occupies the period; a second active one still cannot appear beside the new run.
    $discarded = app(PayrollRunService::class)->discard($first, 'Prepared for the wrong month', $this->maker);
    $second = payrollDraft($this);
    expect($discarded->status)->toBe(PayrollRunStatus::Rejected)
        ->and($second->id)->not->toBe($first->id)
        ->and(fn () => app(PayrollRunService::class)->validate($discarded->fresh(), $this->maker))->toThrow(ValidationException::class, 'already exists')
        ->and(PayrollRun::query()->where('branch_id', $this->branch->id)->whereNotNull('active_key')->count())->toBe(1);
});

test('a run needs an open period', function () {
    payrollEmployee($this);
    $this->period->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => payrollDraft($this))->toThrow(ValidationException::class, 'must both be open');
});

// --- Accrual posting --------------------------------------------------------------

test('posting is blocked while the payroll policy is not approved', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    payrollConfigure($this, policy: false);
    $run = payrollApproved($this);

    expect(fn () => app(PayrollRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class, 'payroll.calculation_policy_approved')
        ->and($run->fresh()->status)->toBe(PayrollRunStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe(0);
    $this->post(route('payroll-runs.post', $run))->assertSessionHasErrors('policy');
    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Payroll calculation policy not approved')->assertSee('Posting blocked');
});

test('posting is blocked when a mapping or a deduction ledger is missing', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    payrollConfigure($this, mappings: false, taxAccount: false);
    $run = payrollApproved($this);
    $service = app(PayrollRunService::class);

    expect(fn () => $service->post($run, $this->maker))->toThrow(ValidationException::class, 'Salary Expense');
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryExpense->value, 'account_id' => $this->salaryExpense->id, 'updated_by' => $this->administrator->id]);
    expect(fn () => $service->post($run, $this->maker))->toThrow(ValidationException::class, 'Salary Payable');
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryPayable->value, 'account_id' => $this->salaryPayable->id, 'updated_by' => $this->administrator->id]);
    expect(fn () => $service->post($run, $this->maker))->toThrow(ValidationException::class, 'Income Tax has no active, postable ledger');

    $this->tax->update(['account_id' => $this->taxPayable->id]);
    $this->taxPayable->update(['is_active' => false]);
    expect(fn () => $service->post($run, $this->maker))->toThrow(ValidationException::class, 'Income Tax has no active, postable ledger')
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and($run->fresh()->status)->toBe(PayrollRunStatus::Approved);
});

test('posting is blocked in a closed period and for a user without journal posting rights', function () {
    payrollEmployee($this);
    payrollConfigure($this);
    $run = payrollApproved($this);
    $poster = User::factory()->create();
    $poster->givePermissionTo(['payroll.view', 'payroll.post']);
    $poster->branches()->attach($this->branch);
    expect(fn () => app(PayrollRunService::class)->post($run, $poster))->toThrow(HttpException::class);

    $this->period->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => app(PayrollRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class, 'must both be open')
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('approved and mapped run posts one balanced accrual journal', function () {
    $bonusExpense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    PayrollComponent::query()->where('code', 'BONUS')->update(['account_id' => $bonusExpense->id]);
    payrollEmployee($this, ['BASIC' => '50000.1234', 'ALLOWANCE' => '2500.0001', 'BONUS' => '1000.0000', 'TAX' => '1250.0005']);
    payrollEmployee($this, ['BASIC' => '30000.0000', 'TAX' => '750.0000']);
    $run = payrollPosted($this);
    $journal = $run->journalEntry()->with(['lines', 'voucherType'])->first();
    $lines = $journal->lines->keyBy('account_id');

    expect($run->status)->toBe(PayrollRunStatus::Posted)
        ->and($run->posted_by)->toBe($this->maker->id)
        ->and($journal->voucherType->code)->toBe('SV')
        ->and($journal->source_type)->toBe('payroll_run')
        ->and($journal->source_key)->toBe('payroll_run:'.$run->id)
        ->and($journal->transaction_date->toDateString())->toBe('2026-01-31')
        ->and($journal->branch_id)->toBe($this->branch->id)
        ->and($journal->lines)->toHaveCount(4)
        ->and($lines[$this->salaryExpense->id]->debit)->toBe('82500.1235')
        ->and($lines[$bonusExpense->id]->debit)->toBe('1000.0000')
        ->and($lines[$this->salaryPayable->id]->credit)->toBe('81500.1230')
        ->and($lines[$this->taxPayable->id]->credit)->toBe('2000.0005')
        ->and($journal->total_debit)->toBe('83500.1235')
        ->and($journal->total_credit)->toBe('83500.1235')
        ->and($run->gross_amount)->toBe('83500.1235')
        ->and(AuditLog::query()->where('event', 'payroll.posted')->exists())->toBeTrue()
        ->and(fn () => app(PayrollRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class);
});

test('posted run and its lines are immutable', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    $run = payrollPosted($this);
    $line = $run->lines()->first();
    $item = $line->items()->first();
    $service = app(PayrollRunService::class);

    expect(fn () => $run->update(['net_amount' => '1.0000']))->toThrow(LogicException::class)
        ->and(fn () => $run->update(['journal_entry_id' => null]))->toThrow(LogicException::class)
        ->and(fn () => $run->delete())->toThrow(LogicException::class)
        ->and(fn () => $line->update(['net_amount' => '1.0000']))->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class)
        ->and(fn () => $item->update(['amount' => '1.0000']))->toThrow(LogicException::class)
        ->and(fn () => $item->delete())->toThrow(LogicException::class)
        ->and(fn () => $run->history()->first()->update(['comments' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $service->updateLine($line, [$this->basic->id => '1.0000'], $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->validate($run, $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => app(PayrollComponentService::class)->update($this->tax, ['name' => 'Tax', 'type' => 'earning', 'category' => 'tax', 'is_active' => true], $this->administrator))->toThrow(ValidationException::class);
    $this->put(route('payroll-runs.lines.update', [$run, $line]), ['amounts' => [$this->basic->id => '1.0000']])->assertSessionHasErrors('status');
    expect($run->fresh()->net_amount)->toBe('49500.0000');
});

// --- Salary payment ------------------------------------------------------------------

test('salary payment goes through the payment engine and debits salary payable without a second expense', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    $run = payrollPosted($this);
    $service = app(PayrollRunService::class);
    $payment = $service->pay($run, payrollPayData($this, '49500.0000'), $this->maker);

    expect($payment->status)->toBe(OperationalTransactionStatus::Draft)
        ->and($payment->type->value)->toBe('bank_payment')
        ->and($payment->contra_account_id)->toBe($this->salaryPayable->id)
        ->and($payment->financial_account_id)->toBe($this->bank->id)
        ->and($payment->source_reference)->toBe('payroll_run:'.$run->id)
        ->and($payment->approval_tier)->toBe('standard')
        ->and($payment->journal_entry_id)->toBeNull()
        ->and(PayrollRunPayment::query()->where('payroll_run_id', $run->id)->where('operational_transaction_id', $payment->id)->exists())->toBeTrue()
        ->and($service->syncPaidStatus($run->fresh())->status)->toBe(PayrollRunStatus::Posted)
        ->and($service->outstandingAmount($run->fresh()))->toBe('0.0000')
        ->and($service->paidAmount($run->fresh()))->toBe('0.0000');

    $posted = payrollSettle($this, $payment);
    $paymentLines = $posted->journalEntry->lines->keyBy('account_id');
    $expenseDebits = JournalLine::query()->where('account_id', $this->salaryExpense->id)->get();

    expect($paymentLines[$this->salaryPayable->id]->debit)->toBe('49500.0000')
        ->and($paymentLines[$this->bank->account_id]->credit)->toBe('49500.0000')
        ->and($posted->journalEntry->lines)->toHaveCount(2)
        ->and($expenseDebits)->toHaveCount(1)
        ->and($expenseDebits->first()->debit)->toBe('50000.0000')
        ->and($expenseDebits->first()->journal_entry_id)->toBe($run->journal_entry_id);

    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Fully paid');
    expect($run->fresh()->status)->toBe(PayrollRunStatus::Paid)
        ->and($run->fresh()->paid_at)->not->toBeNull()
        ->and(fn () => $service->pay($run->fresh(), payrollPayData($this, '1.0000'), $this->maker))->toThrow(ValidationException::class);
});

test('over-payment and duplicate payment are prevented', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    $run = payrollPosted($this);
    $service = app(PayrollRunService::class);

    expect(fn () => $service->pay($run, payrollPayData($this, '49500.0001'), $this->maker))->toThrow(ValidationException::class, 'exceeds the unpaid net salary');
    $first = $service->pay($run, payrollPayData($this, '49500.0000'), $this->maker);
    expect(fn () => $service->pay($run->fresh(), payrollPayData($this, '49500.0000'), $this->maker))->toThrow(ValidationException::class, 'exceeds the unpaid net salary')
        ->and(fn () => $service->pay($run->fresh(), payrollPayData($this, '0.0001'), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => PayrollRunPayment::query()->create(['payroll_run_id' => $run->id, 'operational_transaction_id' => $first->id, 'created_by' => $this->maker->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and(OperationalTransaction::query()->count())->toBe(1);

    // A rejected payment releases the amount it reserved.
    $engine = app(OperationalTransactionService::class);
    $engine->submit($first, $this->maker);
    $engine->reject($first->fresh(), 'Wrong bank account', $this->approver);
    expect($service->outstandingAmount($run->fresh()))->toBe('49500.0000')
        ->and($service->pay($run->fresh(), payrollPayData($this, '49500.0000'), $this->maker)->status)->toBe(OperationalTransactionStatus::Draft);
});

test('salary payment needs the payment permissions of the engine and a payable mapping', function () {
    payrollEmployee($this);
    $run = payrollPosted($this);
    $payer = User::factory()->create();
    $payer->givePermissionTo(['payroll.view', 'payroll.pay']);
    $payer->branches()->attach($this->branch);

    expect(fn () => app(PayrollRunService::class)->pay($run, payrollPayData($this, '100.0000'), $payer))->toThrow(HttpException::class)
        ->and(fn () => app(PayrollRunService::class)->pay($run, [...payrollPayData($this, '100.0000'), 'type' => 'expense'], $this->maker))->toThrow(ValidationException::class)
        ->and(OperationalTransaction::query()->count())->toBe(0)
        ->and(PayrollRunPayment::query()->count())->toBe(0);
});

test('salary payment above the threshold needs director approval', function () {
    payrollEmployee($this, ['BASIC' => '200000.0000']);
    $run = payrollPosted($this);
    $engine = app(OperationalTransactionService::class);
    $payment = app(PayrollRunService::class)->pay($run, payrollPayData($this, '200000.0000'), $this->maker);
    $engine->submit($payment, $this->maker);
    $payment = $payment->fresh();

    expect($payment->approval_tier)->toBe('director')
        ->and($engine->canApprove($payment, $this->administrator))->toBeFalse()
        ->and(fn () => $engine->approve($payment, $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $engine->approve($payment, $this->approver))->toThrow(HttpException::class)
        ->and($payment->fresh()->status)->toBe(OperationalTransactionStatus::Submitted)
        ->and($engine->approve($payment, $this->director)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and(app(PayrollRunService::class)->syncPaidStatus($run->fresh())->status)->toBe(PayrollRunStatus::Posted);
});

test('run becomes paid only when posted payments equal the net amount', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    $run = payrollPosted($this);
    $service = app(PayrollRunService::class);

    payrollSettle($this, $service->pay($run, payrollPayData($this, '20000.0000'), $this->maker));
    expect($service->syncPaidStatus($run->fresh())->status)->toBe(PayrollRunStatus::Posted)
        ->and($service->paidAmount($run->fresh()))->toBe('20000.0000')
        ->and($service->outstandingAmount($run->fresh()))->toBe('29500.0000');

    $second = $service->pay($run->fresh(), payrollPayData($this, '29500.0000'), $this->maker);
    expect($service->syncPaidStatus($run->fresh())->status)->toBe(PayrollRunStatus::Posted);
    payrollSettle($this, $second);
    $paid = $service->syncPaidStatus($run->fresh());

    expect($paid->status)->toBe(PayrollRunStatus::Paid)
        ->and($service->paidAmount($paid))->toBe('49500.0000')
        ->and(PayrollRunHistory::query()->where('payroll_run_id', $run->id)->where('action', 'paid')->count())->toBe(1)
        ->and(JournalLine::query()->where('account_id', $this->salaryExpense->id)->count())->toBe(1);
});

// --- Reversal ------------------------------------------------------------------------

test('posted unpaid run can be reversed and frees its period', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    $run = payrollPosted($this);
    $service = app(PayrollRunService::class);

    expect(fn () => $service->reverse($run, '2026-01-31', 'Posted in error', $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $service->reverse($run, '2027-03-01', 'Posted in error', $this->maker))->toThrow(ValidationException::class);

    $reversed = $service->reverse($run, '2026-01-31', 'Posted in error', $this->maker);
    expect($reversed->status)->toBe(PayrollRunStatus::Reversed)
        ->and($reversed->active_key)->toBeNull()
        ->and($reversed->journalEntry->status->value)->toBe('reversed')
        ->and(JournalEntry::query()->where('source_type', 'journal_reversal')->where('reversal_of_id', $run->journal_entry_id)->where('status', 'posted')->exists())->toBeTrue()
        ->and(fn () => $service->reverse($reversed, '2026-01-31', 'Again please', $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $reversed->update(['status' => PayrollRunStatus::Posted]))->toThrow(LogicException::class)
        ->and(payrollDraft($this)->status)->toBe(PayrollRunStatus::Draft);
});

test('a run with a linked payment cannot be reversed until the payment is rejected', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000']);
    $run = payrollPosted($this);
    $service = app(PayrollRunService::class);
    $payment = $service->pay($run, payrollPayData($this, '10000.0000'), $this->maker);

    expect(fn () => $service->reverse($run->fresh(), '2026-01-31', 'Posted in error', $this->maker))->toThrow(ValidationException::class, 'linked salary payments');
    $engine = app(OperationalTransactionService::class);
    $engine->submit($payment, $this->maker);
    $engine->reject($payment->fresh(), 'Run is being reversed', $this->approver);
    expect($service->reverse($run->fresh(), '2026-01-31', 'Posted in error', $this->maker)->status)->toBe(PayrollRunStatus::Reversed);
});

test('unapproved or unposted runs cannot be reversed or paid', function () {
    payrollEmployee($this);
    payrollConfigure($this);
    $run = payrollApproved($this);
    $service = app(PayrollRunService::class);

    expect(fn () => $service->reverse($run, '2026-01-31', 'Not posted yet', $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->pay($run, payrollPayData($this, '100.0000'), $this->maker))->toThrow(ValidationException::class)
        ->and(OperationalTransaction::query()->count())->toBe(0);
});

// --- Components and screens -------------------------------------------------------------

test('default components carry no ledger and component ledgers are type checked', function () {
    $service = app(PayrollComponentService::class);
    expect(PayrollComponent::query()->whereNotNull('account_id')->count())->toBe(0)
        ->and(PayrollComponent::query()->pluck('code')->all())->toContain('BASIC', 'ALLOWANCE', 'BONUS', 'ARREARS', 'ADVANCE', 'TAX', 'OTHER_DED')
        ->and(fn () => $service->create(['code' => 'EOBI', 'name' => 'Employer scheme', 'type' => 'deduction', 'category' => 'statutory', 'account_id' => $this->salaryExpense->id, 'is_active' => true], $this->administrator))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(['code' => 'MEAL', 'name' => 'Meal', 'type' => 'earning', 'category' => 'allowance', 'account_id' => $this->taxPayable->id, 'is_active' => true], $this->administrator))->toThrow(ValidationException::class);

    $this->actingAs($this->administrator)->post(route('payroll-components.store'), ['code' => 'stat1', 'name' => 'Statutory scheme', 'type' => 'deduction', 'category' => 'statutory', 'account_id' => $this->taxPayable->id, 'is_active' => '1', 'sort_order' => 80])->assertRedirect(route('payroll-components.index'));
    $component = PayrollComponent::query()->where('code', 'STAT1')->firstOrFail();
    $this->actingAs($this->administrator)->put(route('payroll-components.update', $component), ['name' => 'Statutory scheme B', 'type' => 'deduction', 'category' => 'statutory', 'is_active' => '0'])->assertRedirect();
    expect($component->fresh()->name)->toBe('Statutory scheme B')
        ->and($component->fresh()->is_active)->toBeFalse()
        ->and($component->fresh()->account_id)->toBeNull();
});

test('employee and salary structure forms work over http', function () {
    $this->post(route('employees.store'), ['employee_code' => 'EMP-NEW-1', 'name' => 'Generated Person', 'branch_id' => $this->branch->id, 'designation' => 'Therapist', 'employment_status' => 'active', 'joined_on' => '2026-01-01', 'bank_details' => 'TESTBANK 123'])->assertRedirect();
    $employee = Employee::query()->where('employee_code', 'EMP-NEW-1')->firstOrFail();
    $this->post(route('employees.store'), ['employee_code' => 'EMP-NEW-1', 'name' => 'Duplicate', 'branch_id' => $this->branch->id, 'employment_status' => 'active'])->assertSessionHasErrors('employee_code');
    $this->post(route('employees.store'), ['employee_code' => 'EMP-NEW-2', 'name' => 'Elsewhere', 'branch_id' => $this->otherBranch->id, 'employment_status' => 'active'])->assertForbidden();

    $this->put(route('employees.salary.update', $employee), ['amounts' => [$this->basic->id => '45000.5000', $this->tax->id => '450.0000', $this->allowance->id => '']])->assertRedirect(route('employees.show', $employee));
    $this->put(route('employees.salary.update', $employee), ['amounts' => [$this->basic->id => '-1']])->assertSessionHasErrors('amounts.'.$this->basic->id);
    $this->put(route('employees.salary.update', $employee), ['amounts' => [$this->basic->id => '1.00001']])->assertSessionHasErrors('amounts.'.$this->basic->id);

    expect($employee->bank_details)->toBe('TESTBANK 123')
        ->and($employee->salaryItems()->count())->toBe(2)
        ->and($employee->salaryItems()->where('payroll_component_id', $this->basic->id)->value('amount'))->toBe('45000.5000')
        ->and(AuditLog::query()->where('event', 'employees.salary_structure_updated')->first()->properties)->not->toHaveKey('amounts');
    $this->get(route('employees.show', $employee))->assertOk()->assertSee('44,550.50');
});

test('payroll screens render', function () {
    $employee = payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    $this->get(route('employees.index', ['search' => $employee->employee_code, 'status' => 'active', 'branch_id' => $this->branch->id]))->assertOk()->assertSee($employee->name);
    $this->get(route('employees.index', ['status' => 'terminated']))->assertOk()->assertSee('No employees found');
    $this->get(route('employees.create'))->assertOk();
    $this->get(route('employees.edit', $employee))->assertOk();
    $this->get(route('employees.show', $employee))->assertOk()->assertSee('Salary structure');
    $this->get(route('payroll-components.index'))->assertOk()->assertSee('Basic Salary')->assertSee('Ledger required before posting');
    $this->get(route('payroll-components.edit', $this->tax))->assertOk();
    $this->get(route('payroll-runs.index'))->assertOk()->assertSee('No payroll runs found')->assertSee('Payroll calculation policy not approved');
    $this->get(route('payroll-runs.create'))->assertOk()->assertSee('January 2026');

    $this->post(route('payroll-runs.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id])->assertRedirect();
    $run = PayrollRun::query()->firstOrFail();
    $this->post(route('payroll-runs.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id])->assertSessionHasErrors('accounting_period_id');
    $this->get(route('payroll-runs.index', ['status' => 'draft', 'branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id]))->assertOk()->assertSee('49,500.00');
    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee($employee->employee_code)->assertSee('Validate')->assertSee('Not validated');
    $this->get(route('payroll-runs.lines.edit', [$run, $run->lines()->first()]))->assertOk()->assertSee('Line amounts');
    $this->put(route('payroll-runs.lines.update', [$run, $run->lines()->first()]), ['amounts' => [$this->basic->id => '60000', $this->tax->id => '600.5']])->assertRedirect(route('payroll-runs.show', $run));
    $this->post(route('payroll-runs.validate', $run))->assertRedirect();
    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Submit for approval')->assertSee('59,399.50');
    $this->post(route('payroll-runs.submit', $run))->assertRedirect();
    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Awaiting approval by another authorized user');
    $this->actingAs($this->approver)->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Approve payroll run');
    $this->actingAs($this->approver)->post(route('payroll-runs.approve', $run), ['comments' => 'Agreed'])->assertRedirect();

    payrollConfigure($this);
    $this->actingAs($this->maker)->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Post accrual')->assertDontSee('Payroll calculation policy not approved');
    $this->post(route('payroll-runs.post', $run))->assertRedirect()->assertSessionHas('status');
    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Create salary payment')->assertSee('No salary payments yet');
    $this->get(route('payroll-runs.register', $run))->assertOk()->assertSee('payroll register')->assertSee('59,399.50');
    $this->post(route('payroll-runs.pay', $run), payrollPayData($this, '59399.5000'))->assertRedirect()->assertSessionHas('status');
    $this->post(route('payroll-runs.pay', $run), payrollPayData($this, '0.0100'))->assertSessionHasErrors('amount');
    $this->get(route('payroll-runs.show', $run))->assertOk()->assertSee('Open in payments')->assertSee('Draft');
    $this->get(route('employees.show', $employee))->assertOk()->assertSee('Payroll history')->assertSee('59,399.50');
    expect($run->fresh()->status)->toBe(PayrollRunStatus::Posted);
});

test('draft lines can be added and removed and a validated run can return to draft', function () {
    $first = payrollEmployee($this);
    $run = payrollDraft($this);
    $second = payrollEmployee($this, ['BASIC' => '1000.0000']);
    $foreign = Employee::factory()->create(['branch_id' => $this->otherBranch->id, 'created_by' => $this->maker->id]);
    $service = app(PayrollRunService::class);

    $this->post(route('payroll-runs.lines.store', $run), ['employee_id' => $second->id])->assertRedirect();
    $this->post(route('payroll-runs.lines.store', $run), ['employee_id' => $second->id])->assertSessionHasErrors('employee_id');
    $this->post(route('payroll-runs.lines.store', $run), ['employee_id' => $foreign->id])->assertSessionHasErrors('employee_id');
    expect($run->fresh()->gross_amount)->toBe('51000.0000');

    $this->delete(route('payroll-runs.lines.destroy', [$run, $run->lines()->where('employee_id', $first->id)->first()]))->assertRedirect();
    expect($run->fresh()->gross_amount)->toBe('1000.0000')->and($run->lines()->count())->toBe(1);

    $validated = $service->validate($run->fresh(), $this->maker);
    expect(fn () => $service->addLine($validated, $first->id, $this->maker))->toThrow(ValidationException::class);
    $this->post(route('payroll-runs.revert', $run))->assertRedirect();
    expect($run->fresh()->status)->toBe(PayrollRunStatus::Draft)
        ->and($service->addLine($run->fresh(), $first->id, $this->maker)->gross_amount)->toBe('50000.0000');
});

// --- Cash and bank protection and draft editors ---------------------------------------------

test('a deduction component cannot point to a cash, bank or petty cash ledger but liabilities and advances are allowed', function () {
    $service = app(PayrollComponentService::class);
    $pettyCash = Account::factory()->create();
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::PettyCash->value, 'account_id' => $pettyCash->id, 'updated_by' => $this->administrator->id]);
    $advances = Account::factory()->create(['name' => 'Staff advances receivable']);

    foreach ([$this->bank->account_id, $pettyCash->id] as $controlled) {
        expect(fn () => $service->create(['code' => 'CASHDED'.$controlled, 'name' => 'Cash deduction', 'type' => 'deduction', 'category' => 'statutory', 'account_id' => $controlled, 'is_active' => true], $this->administrator))
            ->toThrow(ValidationException::class, 'cannot use a cash, bank or petty cash ledger')
            ->and(fn () => $service->update($this->tax, ['name' => 'Income Tax', 'type' => 'deduction', 'category' => 'tax', 'account_id' => $controlled, 'is_active' => true], $this->administrator))
            ->toThrow(ValidationException::class, 'cannot use a cash, bank or petty cash ledger');
    }
    $this->actingAs($this->administrator)->put(route('payroll-components.update', $this->tax), ['name' => 'Income Tax', 'type' => 'deduction', 'category' => 'tax', 'account_id' => $this->bank->account_id, 'is_active' => '1'])->assertSessionHasErrors('account_id');

    expect($service->update($this->tax, ['name' => 'Income Tax', 'type' => 'deduction', 'category' => 'tax', 'account_id' => $this->taxPayable->id, 'is_active' => true], $this->administrator)->account_id)->toBe($this->taxPayable->id)
        ->and($service->create(['code' => 'ADV2', 'name' => 'Advance recovery', 'type' => 'deduction', 'category' => 'statutory', 'account_id' => $advances->id, 'is_active' => true], $this->administrator)->account_id)->toBe($advances->id)
        ->and($service->eligibleAccounts(PayrollComponentType::Deduction)->pluck('id')->all())->toContain($advances->id, $this->taxPayable->id)
        ->not->toContain($this->bank->account_id, $pettyCash->id);
});

test('posting refuses a deduction that was pointed at a bank ledger outside the component form', function () {
    payrollEmployee($this, ['BASIC' => '50000.0000', 'TAX' => '500.0000']);
    payrollConfigure($this);
    $run = payrollApproved($this);
    // A ledger that became a bank account after the component was saved.
    $this->tax->update(['account_id' => $this->bank->account_id]);

    expect(fn () => app(PayrollRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class, 'cash, bank or petty cash ledger')
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and($run->fresh()->status)->toBe(PayrollRunStatus::Approved);

    $this->tax->update(['account_id' => $this->taxPayable->id]);
    expect(app(PayrollRunService::class)->post($run->fresh(), $this->maker)->status)->toBe(PayrollRunStatus::Posted);
});

test('a user who edited payroll lines cannot approve the run another user submitted', function () {
    payrollEmployee($this);
    $second = payrollEmployee($this);
    $service = app(PayrollRunService::class);
    $run = $service->createFromStructures(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id], $this->maker);
    $run->lines()->where('employee_id', $second->id)->first()->items()->get()->each->delete();
    $run->lines()->where('employee_id', $second->id)->delete();
    $editor = payrollUser(SystemRole::Approver, $this->branch);
    $editor->givePermissionTo('payroll.manage');

    $service->updateLine($run->lines()->first(), [$this->basic->id => '51000.0000'], $editor);
    $service->addLine($run->fresh(), $second->id, $editor);
    $service->removeLine($run->lines()->where('employee_id', $second->id)->first(), $editor);
    expect(AuditLog::query()->where('auditable_type', $run->getMorphClass())->where('auditable_id', $run->id)->whereIn('event', ['payroll.line_updated', 'payroll.line_added', 'payroll.line_removed'])->pluck('actor_id')->unique()->values()->all())->toBe([$editor->id]);

    $service->submit($service->validate($run->fresh(), $this->maker), $this->maker);
    expect($service->canApprove($run->fresh(), $editor))->toBeFalse()
        ->and(fn () => $service->approve($run->fresh(), $editor))->toThrow(ValidationException::class, 'You edited this payroll run')
        ->and($service->approve($run->fresh(), $this->approver)->status)->toBe(PayrollRunStatus::Approved);
});

test('the unified payment screen pays salary through the payroll workflow, its salary payable debit and its cap', function () {
    payrollEmployee($this);
    $run = payrollPosted($this);
    $payments = app(UnifiedPaymentService::class);
    $data = ['payment_purpose_id' => PaymentPurpose::query()->where('code', 'salary_payable')->value('id'),
        'payroll_run_id' => $run->id, 'branch_id' => $run->branch_id, 'transaction_date' => '2026-01-31', 'financial_account_id' => $this->bank->id];

    $payment = $payments->create([...$data, 'amount' => (string) $run->net_amount], $this->maker);
    expect($payment->contra_account_id)->toBe($this->salaryPayable->id)->and($payment->payee_snapshot['purpose']['code'])->toBe('salary_payable')
        ->and(PayrollRunPayment::query()->where('payroll_run_id', $run->id)->where('operational_transaction_id', $payment->id)->exists())->toBeTrue()
        ->and(fn () => $payments->create([...$data, 'amount' => '0.01'], $this->maker))->toThrow(ValidationException::class, 'unpaid net salary')
        ->and(fn () => $payments->create([...$data, 'amount' => '1.00', 'payee_type_id' => PayeeType::query()->where('code', 'vendor')->value('id')], $this->maker))->toThrow(ValidationException::class, 'cannot be used')
        ->and(fn () => $payments->create([...$data, 'amount' => '1.00'], $this->approver))->toThrow(HttpException::class);
});
