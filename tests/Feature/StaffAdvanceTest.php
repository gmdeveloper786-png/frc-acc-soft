<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\StaffAdvanceStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Services\Payroll\StaffAdvanceRecoveryService;
use App\Services\Payroll\StaffAdvanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish', 'name' => 'Advance Other Branch']);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->periods = collect(['January', 'February', 'March', 'April', 'May', 'June'])->map(fn (string $month, int $index) => AccountingPeriod::factory()->create([
        'fiscal_year_id' => $year, 'name' => $month.' 2026', 'period_number' => $index + 1, 'status' => AccountingPeriodStatus::Open,
        'start_date' => sprintf('2026-%02d-01', $index + 1), 'end_date' => now()->setDate(2026, $index + 1, 1)->endOfMonth()->toDateString(),
    ]));
    $this->maker = advanceUser(SystemRole::Accountant, $this->branch);
    $this->approver = advanceUser(SystemRole::Approver, $this->branch);
    $this->administrator = advanceUser(SystemRole::Administrator, $this->branch);

    $this->advanceLedger = Account::factory()->create(['account_type' => 'asset', 'name' => 'Staff Advances']);
    $this->recovery = PayrollComponent::query()->where('code', 'ADVANCE')->firstOrFail();
    $this->recovery->update(['account_id' => $this->advanceLedger->id]);
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->maker->id, 'employee_code' => 'EMP-ADV1', 'name' => 'Advance Employee']);
    $this->employee->salaryItems()->create(['payroll_component_id' => PayrollComponent::query()->where('code', 'BASIC')->value('id'), 'amount' => '50000.0000']);
    $this->service = app(StaffAdvanceService::class);
    $this->actingAs($this->maker);
});

function advanceUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function advanceData(object $test, array $overrides = []): array
{
    return [
        'employee_id' => $test->employee->id, 'type' => 'advance', 'principal_amount' => '1000', 'instalment_count' => 2, 'instalment_amount' => null,
        'first_recovery_period_id' => $test->periods[0]->id, 'payroll_component_id' => $test->recovery->id, 'purpose' => 'Medical emergency', ...$overrides,
    ];
}

function advanceApproved(object $test, array $overrides = []): StaffAdvance
{
    $advance = $test->service->create(advanceData($test, $overrides), $test->maker);
    $test->service->submit($advance, $test->maker);

    return $test->service->approve($advance->fresh(), $test->approver);
}

/** A posted payment of the payment workflow that paid the advance out. */
function advancePayment(object $test, string $amount = '1000.0000', array $overrides = []): OperationalTransaction
{
    $bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $test->branch, 'account_id' => Account::factory()]);

    return OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::BankPayment, 'status' => OperationalTransactionStatus::Posted, 'branch_id' => $test->branch->id,
        'amount' => $amount, 'financial_account_id' => $bank->id, 'contra_account_id' => $test->advanceLedger->id, 'created_by' => $test->maker->id, 'posted_at' => now(), ...$overrides,
    ]);
}

function advanceActive(object $test, array $overrides = []): StaffAdvance
{
    $advance = advanceApproved($test, $overrides);

    return $test->service->linkDisbursement($advance, advancePayment($test, (string) $advance->principal_amount)->id, $test->maker);
}

function advanceConfigurePayroll(object $test): void
{
    AppSetting::query()->where('key', 'payroll.calculation_policy_approved')->first()->update(['value' => '1']);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryExpense->value, 'account_id' => Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure'])->id, 'updated_by' => $test->administrator->id]);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryPayable->value, 'account_id' => Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit'])->id, 'updated_by' => $test->administrator->id]);
}

/** Prepare, approve and post the payroll run of a period, entering the due recoveries first. */
function advancePostRun(object $test, AccountingPeriod $period): PayrollRun
{
    $runs = app(PayrollRunService::class);
    $run = $runs->createFromStructures(['branch_id' => $test->branch->id, 'accounting_period_id' => $period->id], $test->maker);
    $test->service->applyDueRecoveries($run, $test->maker);
    $run = $runs->validate($run->fresh(), $test->maker);
    $runs->submit($run, $test->maker);
    $runs->approve($run->fresh(), $test->approver);

    return $runs->post($run->fresh(), $test->maker);
}

// --- Schedule ------------------------------------------------------------------

test('the schedule splits the principal exactly and the last instalment absorbs rounding', function () {
    expect($this->service->instalmentAmounts('1000', 3, null))->toBe(['333.3300', '333.3300', '333.3400'])
        ->and($this->service->instalmentAmounts('1000.0050', 3, null))->toBe(['333.3300', '333.3300', '333.3450'])
        ->and($this->service->instalmentAmounts('1000', null, '300'))->toBe(['300.0000', '300.0000', '300.0000', '100.0000'])
        ->and($this->service->instalmentAmounts('900', null, '300'))->toBe(['300.0000', '300.0000', '300.0000'])
        ->and($this->service->instalmentAmounts('0.05', 1, null))->toBe(['0.0500']);

    $advance = $this->service->create(advanceData($this, ['principal_amount' => '10000.01', 'instalment_count' => 3]), $this->maker);
    $instalments = $advance->instalments()->with('period')->get();

    expect($advance->reference)->toBe('ADV-'.str_pad((string) $advance->id, 6, '0', STR_PAD_LEFT))
        ->and($advance->status)->toBe(StaffAdvanceStatus::Draft)
        ->and($instalments->pluck('amount')->all())->toBe(['3333.3300', '3333.3300', '3333.3500'])
        ->and($instalments->pluck('period.name')->all())->toBe(['January 2026', 'February 2026', 'March 2026'])
        ->and($advance->outstandingAmount())->toBe('10000.0100');
});

test('invalid schedules are refused', function () {
    expect(fn () => $this->service->instalmentAmounts('1000', 2, '500'))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->instalmentAmounts('1000', null, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->instalmentAmounts('1000', null, '1500'))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->instalmentAmounts('0.01', 3, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->instalmentAmounts('1000', 121, null))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->create(advanceData($this, ['instalment_count' => 12]), $this->maker))->toThrow(ValidationException::class);

    $this->periods[0]->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => $this->service->create(advanceData($this), $this->maker))->toThrow(ValidationException::class)
        ->and(StaffAdvance::query()->count())->toBe(0);
});

test('a recovery component must be a deduction whose ledger is not cash or bank', function () {
    $bankLedger = Account::factory()->create();
    FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => $bankLedger]);
    $cashDeduction = PayrollComponent::factory()->deduction()->create(['account_id' => $bankLedger->id]);
    $noLedger = PayrollComponent::factory()->deduction()->create();
    $earning = PayrollComponent::query()->where('code', 'BASIC')->firstOrFail();

    foreach ([$cashDeduction, $noLedger, $earning] as $component) {
        expect(fn () => $this->service->create(advanceData($this, ['payroll_component_id' => $component->id]), $this->maker))
            ->toThrow(ValidationException::class);
    }
    expect($this->service->eligibleComponents()->pluck('id')->all())->toBe([$this->recovery->id]);

    // A component re-pointed at a bank ledger after submission can no longer be approved.
    $advance = $this->service->create(advanceData($this), $this->maker);
    $this->service->submit($advance, $this->maker);
    $this->recovery->update(['account_id' => $bankLedger->id]);
    expect(fn () => $this->service->approve($advance->fresh(), $this->approver))->toThrow(ValidationException::class);
});

// --- Workflow ------------------------------------------------------------------

test('maker checker: the preparer submitter and editors cannot approve', function () {
    $editor = advanceUser(SystemRole::Accountant, $this->branch);
    $editor->givePermissionTo('staff_advances.approve');
    $this->maker->givePermissionTo('staff_advances.approve');

    $advance = $this->service->create(advanceData($this), $this->maker);
    $this->service->update($advance, advanceData($this, ['principal_amount' => '1200']), $editor);
    $this->service->submit($advance->fresh(), $this->maker);

    expect(fn () => $this->service->approve($advance->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->approve($advance->fresh(), $editor))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->reject($advance->fresh(), 'Not allowed', $editor))->toThrow(ValidationException::class)
        ->and($this->service->canApprove($advance->fresh(), $this->approver))->toBeTrue()
        ->and($this->service->canApprove($advance->fresh(), $editor))->toBeFalse();
    // Terms are frozen once submitted.
    expect(fn () => $this->service->update($advance->fresh(), advanceData($this), $this->maker))->toThrow(ValidationException::class);

    $returned = $this->service->reject($advance->fresh(), 'Attach the request form', $this->approver);
    expect($returned->status)->toBe(StaffAdvanceStatus::Draft)->and($returned->rejection_reason)->toBe('Attach the request form');

    $this->service->submit($returned, $this->maker);
    $approved = $this->service->approve($advance->fresh(), $this->approver, 'OK');
    expect($approved->status)->toBe(StaffAdvanceStatus::Approved)->and($approved->approved_by)->toBe($this->approver->id)
        ->and($approved->history()->pluck('action')->all())->toBe(['created', 'submitted', 'rejected', 'submitted', 'approved'])
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('only an undisbursed advance can be cancelled', function () {
    $draft = $this->service->create(advanceData($this), $this->maker);
    expect($this->service->cancel($draft, 'Employee withdrew', $this->maker)->status)->toBe(StaffAdvanceStatus::Cancelled)
        ->and(fn () => $this->service->submit($draft->fresh(), $this->maker))->toThrow(ValidationException::class);

    $approved = advanceApproved($this);
    expect($this->service->cancel($approved, 'Not needed', $this->approver)->status)->toBe(StaffAdvanceStatus::Cancelled);

    $active = advanceActive($this);
    expect($active->status)->toBe(StaffAdvanceStatus::Active)
        ->and(fn () => $this->service->cancel($active, 'Too late now', $this->maker))->toThrow(ValidationException::class);
});

test('a disbursement must be a posted cash or bank payment of the branch for the principal debiting the advance ledger', function () {
    $advance = advanceApproved($this);
    $wrongAmount = advancePayment($this, '999.0000');
    $draftPayment = advancePayment($this, '1000.0000', ['status' => OperationalTransactionStatus::Approved]);
    $otherBranch = advancePayment($this, '1000.0000', ['branch_id' => $this->otherBranch->id]);
    $wrongLedger = advancePayment($this, '1000.0000', ['contra_account_id' => Account::factory()->create(['account_type' => 'expense'])->id]);
    $receipt = advancePayment($this, '1000.0000', ['type' => OperationalTransactionType::BankReceipt]);

    foreach ([$wrongAmount, $draftPayment, $otherBranch, $wrongLedger, $receipt] as $payment) {
        expect(fn () => $this->service->linkDisbursement($advance->fresh(), $payment->id, $this->maker))->toThrow(ValidationException::class);
    }
    expect(fn () => $this->service->linkDisbursement($this->service->create(advanceData($this), $this->maker), advancePayment($this)->id, $this->maker))->toThrow(ValidationException::class);

    $good = advancePayment($this);
    expect($this->service->eligibleDisbursements($advance->fresh())->pluck('id')->all())->toContain($good->id)
        ->not->toContain($wrongAmount->id, $draftPayment->id, $otherBranch->id, $wrongLedger->id, $receipt->id);
    $active = $this->service->linkDisbursement($advance->fresh(), $good->id, $this->maker);
    expect($active->status)->toBe(StaffAdvanceStatus::Active)->and($active->disbursement_transaction_id)->toBe($good->id)
        ->and(JournalEntry::query()->count())->toBe(0);

    // The same payment cannot pay out a second advance.
    $second = advanceApproved($this);
    expect(fn () => $this->service->linkDisbursement($second, $good->id, $this->maker))->toThrow(ValidationException::class);
});

// --- Recovery -------------------------------------------------------------------

test('posted payroll runs recover instalments, settle the advance and a reversal reopens it', function () {
    advanceConfigurePayroll($this);
    $advance = advanceActive($this);

    $run = app(PayrollRunService::class)->createFromStructures(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->periods[0]->id], $this->maker);
    $due = app(StaffAdvanceRecoveryService::class)->dueForRun($run);
    expect($due)->toHaveCount(1)->and($due->first()['due'])->toBe('500.0000')->and($due->first()['advances'])->toBe([$advance->reference]);
    // Preparing a run alone records nothing.
    expect($this->service->applyDueRecoveries($run, $this->maker))->toBe(1)
        ->and($advance->fresh()->instalments->sum(fn ($instalment) => (float) $instalment->recovered_amount))->toEqual(0.0);
    app(PayrollRunService::class)->discard($run->fresh(), 'Prepared again below', $this->maker);

    $january = advancePostRun($this, $this->periods[0]);
    $line = $january->lines()->first();
    $advance->refresh();
    expect($line->items()->where('payroll_component_id', $this->recovery->id)->value('amount'))->toBe('500.0000')
        ->and($line->net_amount)->toBe('49500.0000')
        ->and($advance->instalments[0]->recovered_amount)->toBe('500.0000')
        ->and($advance->instalments[0]->payroll_run_line_id)->toBe($line->id)
        ->and($advance->instalments[1]->recovered_amount)->toBe('0.0000')
        ->and($advance->status)->toBe(StaffAdvanceStatus::Active)
        ->and($advance->outstandingAmount())->toBe('500.0000');

    $february = advancePostRun($this, $this->periods[1]);
    $advance->refresh();
    expect($advance->status)->toBe(StaffAdvanceStatus::Settled)->and($advance->settled_at)->not->toBeNull()
        ->and($advance->outstandingAmount())->toBe('0.0000')
        ->and(app(StaffAdvanceRecoveryService::class)->dueForRun($february))->toHaveCount(0);

    app(PayrollRunService::class)->reverse($february->fresh(), '2026-02-28', 'Wrong period posted', $this->maker);
    $advance->refresh();
    expect($advance->status)->toBe(StaffAdvanceStatus::Active)->and($advance->outstandingAmount())->toBe('500.0000')
        ->and($advance->history()->pluck('action')->all())->toContain('settled', 'reopened');
});

test('a deduction only recovers instalments due in or before its period', function () {
    advanceConfigurePayroll($this);
    $advance = advanceActive($this, ['first_recovery_period_id' => $this->periods[1]->id]);
    $this->employee->salaryItems()->create(['payroll_component_id' => $this->recovery->id, 'amount' => '700.0000']);

    // January deducts 700 of the recovery component, but nothing is due before February.
    advancePostRun($this, $this->periods[0]);
    expect($advance->fresh()->recoveredAmount())->toBe('0.0000');

    // February deducts the 500 due (the due amount replaces the salary-structure amount on the line).
    advancePostRun($this, $this->periods[1]);
    expect($advance->fresh()->recoveredAmount())->toBe('500.0000');
});

// --- Access ----------------------------------------------------------------------

test('advances of another branch are invisible and forbidden', function () {
    $advance = advanceActive($this);
    $stranger = advanceUser(SystemRole::Accountant, $this->otherBranch);
    $strangerApprover = advanceUser(SystemRole::Approver, $this->otherBranch);

    $this->actingAs($stranger)->get(route('staff-advances.index'))->assertOk()->assertDontSee($advance->reference);
    $this->actingAs($stranger)->get(route('staff-advances.show', $advance))->assertForbidden();
    $this->actingAs($stranger)->post(route('staff-advances.cancel', $advance), ['reason' => 'Not mine at all'])->assertForbidden();
    expect(fn () => $this->service->create(advanceData($this), $stranger))->toThrow(HttpException::class);

    $submitted = $this->service->create(advanceData($this), $this->maker);
    $this->service->submit($submitted, $this->maker);
    expect(fn () => $this->service->approve($submitted->fresh(), $strangerApprover))->toThrow(HttpException::class)
        ->and($this->service->canApprove($submitted->fresh(), $strangerApprover))->toBeFalse();
});

test('amounts are hidden without the sensitive payroll permission and screens render', function () {
    $advance = advanceActive($this, ['principal_amount' => '4321.5']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['staff_advances.view', 'employees.view', 'payroll.view']);
    $viewer->branches()->attach($this->branch);

    $this->actingAs($viewer)->get(route('staff-advances.index'))->assertOk()->assertSee($advance->reference)->assertDontSee('4,321.50');
    $this->actingAs($viewer)->get(route('staff-advances.show', $advance))->assertOk()->assertSee('Advance Employee')->assertDontSee('4,321.50')->assertDontSee('Recovery schedule');
    $this->actingAs($viewer)->get(route('employees.show', $this->employee))->assertOk()->assertDontSee('Outstanding staff advances');
    $this->actingAs($viewer)->get(route('staff-advances.create'))->assertForbidden();

    $this->actingAs($this->maker)->get(route('staff-advances.index'))->assertOk()->assertSee('4,321.50')->assertSee('Staff Advances');
    $this->actingAs($this->maker)->get(route('staff-advances.show', $advance))->assertOk()->assertSee('4,321.50')->assertSee('Recovery schedule')->assertSee('2,160.75');
    $this->actingAs($this->maker)->get(route('employees.show', $this->employee))->assertOk()->assertSee('Outstanding staff advances')->assertSee($advance->reference);
    $this->actingAs($this->maker)->get(route('staff-advances.index', ['status' => 'settled']))->assertOk()->assertDontSee($advance->reference);
});

test('advances can be prepared submitted and approved over http', function () {
    $this->get(route('staff-advances.create'))->assertOk()->assertSee('Recovery deduction component');
    $this->post(route('staff-advances.store'), [...advanceData($this), 'instalment_count' => '', 'instalment_amount' => '400'])->assertRedirect();
    $advance = StaffAdvance::query()->firstOrFail();
    expect($advance->instalments()->pluck('amount')->all())->toBe(['400.0000', '400.0000', '200.0000']);

    $this->post(route('staff-advances.store'), [...advanceData($this), 'instalment_count' => 2, 'instalment_amount' => '400'])->assertSessionHasErrors('instalment_count');
    $this->get(route('staff-advances.edit', $advance))->assertOk();
    $this->put(route('staff-advances.update', $advance), advanceData($this, ['instalment_count' => 4]))->assertRedirect();
    expect($advance->instalments()->count())->toBe(4);

    $this->post(route('staff-advances.submit', $advance))->assertRedirect();
    $this->post(route('staff-advances.approve', $advance))->assertForbidden();
    $this->actingAs($this->approver)->post(route('staff-advances.approve', $advance))->assertSessionHasNoErrors();
    expect($advance->fresh()->status)->toBe(StaffAdvanceStatus::Approved);

    $payment = advancePayment($this);
    $this->actingAs($this->maker)->get(route('staff-advances.show', $advance))->assertOk()->assertSee('#'.$payment->id);
    $this->post(route('staff-advances.disbursement', $advance), ['operational_transaction_id' => $payment->id])->assertSessionHasNoErrors();
    expect($advance->fresh()->status)->toBe(StaffAdvanceStatus::Active);
    $this->get(route('staff-advances.edit', $advance))->assertForbidden();
});

// --- Report ----------------------------------------------------------------------

test('outstanding report access, content, export and branch isolation', function () {
    $advance = advanceActive($this, ['principal_amount' => '2468.24']);
    $otherEmployee = Employee::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'Advance Stranger Employee']);
    $viewAll = advanceUser(SystemRole::Accountant, $this->otherBranch);
    StaffAdvance::query()->create([
        'reference' => 'ADV-OTHER', 'employee_id' => $otherEmployee->id, 'branch_id' => $this->otherBranch->id, 'type' => 'advance', 'principal_amount' => '7777.7700',
        'instalment_count' => 1, 'first_recovery_period_id' => $this->periods[0]->id, 'payroll_component_id' => $this->recovery->id, 'purpose' => 'Other', 'status' => StaffAdvanceStatus::Active, 'created_by' => $viewAll->id,
    ]);

    $this->get(route('payroll-reports.show', 'advances-outstanding'))->assertOk()->assertSee($advance->reference)->assertSee('2,468.24')
        ->assertDontSee('ADV-OTHER')->assertDontSee('7,777.77');
    $csv = $this->get(route('payroll-reports.export', ['report' => 'advances-outstanding', 'format' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain($advance->reference)->toContain('2468.24')->not->toContain('ADV-OTHER');
    $this->get(route('payroll-reports.export', ['report' => 'advances-outstanding', 'format' => 'xlsx']))->assertOk();
    $this->get(route('payroll-reports.export', ['report' => 'advances-outstanding', 'format' => 'pdf']))->assertOk();
    $this->get(route('payroll-reports.show', ['report' => 'advances-outstanding', 'branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->get(route('payroll-reports.show', ['report' => 'advances-outstanding', 'status' => 'settled']))->assertOk()->assertDontSee($advance->reference);

    $plain = User::factory()->create();
    $plain->givePermissionTo(['payroll.view', 'staff_advances.view']);
    $plain->branches()->attach($this->branch);
    $this->actingAs($plain)->get(route('payroll-reports.show', 'advances-outstanding'))->assertForbidden();
    $this->actingAs($plain)->get(route('payroll-reports.export', ['report' => 'advances-outstanding', 'format' => 'csv']))->assertForbidden();

    $noExport = User::factory()->create();
    $noExport->givePermissionTo(['payroll.view', 'payroll.view_sensitive']);
    $noExport->branches()->attach($this->branch);
    $this->actingAs($noExport)->get(route('payroll-reports.show', 'advances-outstanding'))->assertOk()->assertSee('2,468.24');
    $this->actingAs($noExport)->get(route('payroll-reports.export', ['report' => 'advances-outstanding', 'format' => 'csv']))->assertForbidden();
});
