<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\PayrollImportStatus;
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
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\PayrollImport;
use App\Models\PayrollImportRow;
use App\Models\PayrollRun;
use App\Models\PayrollRunPayment;
use App\Models\User;
use App\Services\OperationalTransactionService;
use App\Services\Payroll\PayrollRunService;
use App\Services\Reports\Payroll\PayrollReportService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Anonymised fixture (January 2026, main branch, posted run):
 *   EMP-A  basic 50000.1200 + allowance 7500.3400 = gross 57500.4600, tax 2500.0600, net 55000.4000
 *   EMP-B  basic 31111.1100                       = gross 31111.1100, tax 1111.1100, net 30000.0000
 *   Run    gross 88611.5700, deductions 3611.1700, net 85000.4000
 *   Payments: 40000.5000 posted, 10000.2500 still a draft -> paid 40000.5000, in progress 10000.2500, outstanding 44999.9000
 * Other branch: EMP-C basic 77777.7700, draft run.
 */
beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Report Main Branch']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish', 'name' => 'Report Other Branch']);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->maker = payrollreportUser(SystemRole::Accountant, $this->branch);
    $this->approver = payrollreportUser(SystemRole::Approver, $this->branch);
    $this->auditor = payrollreportUser(SystemRole::Auditor, $this->branch);
    $this->management = payrollreportUser(SystemRole::Management, $this->branch);
    $this->administrator = payrollreportUser(SystemRole::Administrator, $this->branch);
    $this->otherMaker = payrollreportUser(SystemRole::Accountant, $this->otherBranch);
    // Reaches payroll reports but holds no sensitive permission.
    $this->viewer = User::factory()->create();
    $this->viewer->givePermissionTo('payroll.view');
    $this->viewer->branches()->attach($this->branch);

    $this->salaryExpense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->salaryPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->taxPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->tax = PayrollComponent::query()->where('code', 'TAX')->firstOrFail();
    AppSetting::query()->where('key', 'payroll.calculation_policy_approved')->first()->update(['value' => '1']);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryExpense->value, 'account_id' => $this->salaryExpense->id, 'updated_by' => $this->administrator->id]);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryPayable->value, 'account_id' => $this->salaryPayable->id, 'updated_by' => $this->administrator->id]);
    $this->tax->update(['account_id' => $this->taxPayable->id]);

    $this->employeeA = payrollreportEmployee($this->branch, $this->maker, 'EMP-A', 'Employee Alpha', ['BASIC' => '50000.1200', 'ALLOWANCE' => '7500.3400', 'TAX' => '2500.0600']);
    $this->employeeB = payrollreportEmployee($this->branch, $this->maker, 'EMP-B', 'Employee Bravo', ['BASIC' => '31111.1100', 'TAX' => '1111.1100']);
    $this->employeeC = payrollreportEmployee($this->otherBranch, $this->otherMaker, 'EMP-C', 'Employee Charlie', ['BASIC' => '77777.7700']);

    $service = app(PayrollRunService::class);
    $run = $service->validate($service->createFromStructures(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id], $this->maker), $this->maker);
    $service->submit($run, $this->maker);
    $service->approve($run->fresh(), $this->approver);
    $this->run = $service->post($run->fresh(), $this->maker);

    $engine = app(OperationalTransactionService::class);
    $this->postedPayment = $service->pay($this->run, ['type' => 'bank_payment', 'financial_account_id' => $this->bank->id, 'amount' => '40000.5000', 'transaction_date' => '2026-01-31', 'reference_number' => 'SAL-REF-1'], $this->maker);
    $engine->submit($this->postedPayment, $this->maker);
    $engine->approve($this->postedPayment->fresh(), $this->approver);
    $engine->disburse($this->postedPayment->fresh(), $this->maker);
    $engine->post($this->postedPayment->fresh(), $this->maker);
    $this->draftPayment = $service->pay($this->run->fresh(), ['type' => 'bank_payment', 'financial_account_id' => $this->bank->id, 'amount' => '10000.2500', 'transaction_date' => '2026-01-31', 'reference_number' => 'SAL-REF-2'], $this->maker);

    $this->otherRun = $service->createFromStructures(['branch_id' => $this->otherBranch->id, 'accounting_period_id' => $this->period->id], $this->otherMaker);
    $this->run = $this->run->fresh();
    $this->actingAs($this->auditor);
});

function payrollreportUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function payrollreportEmployee(Branch $branch, User $creator, string $code, string $name, array $amounts): Employee
{
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'created_by' => $creator->id, 'employee_code' => $code, 'name' => $name]);
    foreach ($amounts as $componentCode => $amount) {
        $employee->salaryItems()->create(['payroll_component_id' => PayrollComponent::query()->where('code', $componentCode)->value('id'), 'amount' => $amount]);
    }

    return $employee;
}

/** Rows of a report as the service builds them for the given user. */
function payrollreportRows(string $report, User $user, array $input = []): array
{
    return app(PayrollReportService::class)->build($report, $user, $input)['table']?->rows ?? [];
}

function payrollreportCsv(object $test, string $report, array $query = []): string
{
    return $test->get(route('payroll-reports.export', ['report' => $report, 'format' => 'csv', ...$query]))->assertOk()->streamedContent();
}

/** The cells of the first CSV row whose first cell (trimmed) equals the label. */
function payrollreportCsvRow(string $csv, string $label): ?array
{
    foreach (preg_split('/\r\n|\n/', $csv) as $line) {
        $cells = str_getcsv($line);
        if (trim((string) ($cells[0] ?? '')) === $label) {
            return $cells;
        }
    }

    return null;
}

/** An import of the main branch with one clean row, one row with an error quoting amounts and one with a warning. */
function payrollreportImport(object $test, ?Branch $branch = null): PayrollImport
{
    $branch ??= $test->branch;
    $import = PayrollImport::query()->create([
        'branch_id' => $branch->id, 'accounting_period_id' => $test->period->id, 'original_filename' => 'january-payroll.xlsx', 'stored_path' => 'payroll-imports/test.xlsx',
        'file_hash' => str_repeat('a', 64), 'status' => PayrollImportStatus::Validated, 'created_by' => $test->maker->id,
        'summary' => ['total_rows' => 3, 'error_rows' => 1, 'warning_rows' => 1, 'import_errors' => []],
    ]);
    $values = fn (string $code, string $name, string $gross, string $deductions, string $net): array => ['employee_code' => $code, 'employee_name' => $name, 'amounts' => [], 'gross' => $gross, 'deductions' => $deductions, 'net' => $net, 'stated_gross' => null, 'stated_net' => null, 'pay_date' => null];
    PayrollImportRow::query()->create(['payroll_import_id' => $import->id, 'row_number' => 2, 'mapped_values' => $values('EMP-A', 'Employee Alpha', '57500.4600', '2500.0600', '55000.4000'), 'errors' => [], 'warnings' => []]);
    PayrollImportRow::query()->create(['payroll_import_id' => $import->id, 'row_number' => 3, 'mapped_values' => $values('EMP-B', 'Employee Bravo', '31111.1100', '1111.1100', '30000.0000'),
        'errors' => ['Gross stated in the file (31999.0000) does not equal the gross computed from the mapped components (31111.1100).'], 'warnings' => []]);
    PayrollImportRow::query()->create(['payroll_import_id' => $import->id, 'row_number' => 4, 'mapped_values' => $values('EMP-X', 'Secret Name', '64321.9900', '0.0000', '64321.9900'),
        'errors' => [], 'warnings' => ['The employee name in the file does not match the employee master.']]);

    return $import;
}

/** Every way a fixture amount could surface in HTML. */
function payrollreportAmountStrings(): array
{
    return [
        '57,500.46', '57500.46', '55,000.40', '55000.40', '50,000.12', '50000.12', '7,500.34', '7500.34', '2,500.06', '2500.06',
        '31,111.11', '31111.11', '1,111.11', '1111.11', '30,000.00', '30000.00', '88,611.57', '88611.57', '3,611.17', '3611.17', '85,000.40', '85000.40',
        '40,000.50', '40000.50', '10,000.25', '10000.25', '44,999.90', '44999.90', '77,777.77', '77777.77', '31999', '31,999', '64321', '64,321',
    ];
}

test('payroll register shows every employee component and total exactly', function () {
    $rows = collect(payrollreportRows('register', $this->auditor, ['run_id' => $this->run->id]));
    $components = PayrollComponent::query()->whereIn('code', ['BASIC', 'ALLOWANCE', 'TAX'])->pluck('id', 'code');
    $alpha = $rows->firstWhere('employee_code', 'EMP-A');
    $bravo = $rows->firstWhere('employee_code', 'EMP-B');
    $total = $rows->firstWhere('employee_code', 'Total');

    expect($rows)->toHaveCount(3)
        ->and($alpha['c'.$components['BASIC']])->toBe('50000.1200')
        ->and($alpha['c'.$components['ALLOWANCE']])->toBe('7500.3400')
        ->and($alpha['c'.$components['TAX']])->toBe('2500.0600')
        ->and([$alpha['gross'], $alpha['deductions'], $alpha['net']])->toBe(['57500.4600', '2500.0600', '55000.4000'])
        ->and($bravo['c'.$components['ALLOWANCE']])->toBeNull()
        ->and([$bravo['gross'], $bravo['deductions'], $bravo['net']])->toBe(['31111.1100', '1111.1100', '30000.0000'])
        ->and($total['c'.$components['BASIC']])->toBe('81111.2300')
        ->and($total['c'.$components['TAX']])->toBe('3611.1700')
        ->and([$total['gross'], $total['deductions'], $total['net']])->toBe(['88611.5700', '3611.1700', '85000.4000'])
        ->and($total['employee'])->toBe('2 employees');

    $this->get(route('payroll-reports.show', ['report' => 'register', 'run_id' => $this->run->id]))->assertOk()
        ->assertSee('Employee Alpha')->assertSee('57,500.46')->assertSee('88,611.57')->assertSee('85,000.40')->assertDontSee('Employee Charlie');
    $this->get(route('payroll-reports.show', 'register'))->assertOk()->assertSee('Select a payroll run to see its register.')->assertDontSee('57,500.46');
});

test('employee payroll history lists the stored amounts of each run of the employee', function () {
    $rows = payrollreportRows('employee-history', $this->auditor, ['employee_id' => $this->employeeA->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['period' => 'January 2026', 'branch' => 'Report Main Branch', 'run' => '#'.$this->run->id, 'status' => 'Posted', 'gross' => '57500.4600', 'deductions' => '2500.0600', 'net' => '55000.4000']);
    $this->get(route('payroll-reports.show', ['report' => 'employee-history', 'employee_id' => $this->employeeA->id]))->assertOk()->assertSee('55,000.40')->assertSee('EMP-A');
    $this->get(route('payroll-reports.show', 'employee-history'))->assertOk()->assertSee('Select an employee')->assertDontSee('EMP-C');
});

test('payroll run summary lists runs with totals per status', function () {
    $rows = collect(payrollreportRows('run-summary', $this->auditor));
    $run = $rows->firstWhere('run', '#'.$this->run->id);
    $posted = $rows->firstWhere('period', 'Total — Posted');

    expect($rows)->toHaveCount(2)
        ->and($run)->toMatchArray(['period' => 'January 2026', 'branch' => 'Report Main Branch', 'status' => 'Posted', 'employees' => '2', 'voucher' => $this->run->journalEntry->voucher_number, 'gross' => '88611.5700', 'deductions' => '3611.1700', 'net' => '85000.4000'])
        ->and($posted)->toMatchArray(['run' => '1 run', 'gross' => '88611.5700', 'deductions' => '3611.1700', 'net' => '85000.4000']);

    // Status and period filters.
    expect(payrollreportRows('run-summary', $this->auditor, ['status' => 'draft']))->toBe([])
        ->and(payrollreportRows('run-summary', $this->auditor, ['period_id' => $this->period->id + 999]))->toBe([]);
    $this->get(route('payroll-reports.show', 'run-summary'))->assertOk()->assertSee('88,611.57')->assertSee('Total — Posted')->assertSee($this->run->journalEntry->voucher_number);
    $this->get(route('payroll-reports.show', ['report' => 'run-summary', 'status' => 'draft']))->assertOk()->assertSee('No payroll records for this selection')->assertDontSee('88,611.57');
});

test('salary accrual summary reports expense payable and deduction liabilities with the voucher number', function () {
    $rows = collect(payrollreportRows('accrual-summary', $this->auditor));
    $voucher = $this->run->journalEntry->voucher_number;

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['period' => 'January 2026', 'status' => 'Posted', 'voucher' => $voucher, 'voucher_date' => '31 Jan 2026', 'expense' => '88611.5700', 'payable' => '85000.4000', 'liabilities' => '3611.1700'])
        ->and($rows[1])->toMatchArray(['period' => 'Total', 'run' => '1 run', 'expense' => '88611.5700', 'payable' => '85000.4000', 'liabilities' => '3611.1700'])
        // Expense is read from the posted journal and equals payable plus liabilities.
        ->and((string) $this->run->journalEntry->lines()->sum('debit'))->toStartWith('88611.57');
    $this->get(route('payroll-reports.show', 'accrual-summary'))->assertOk()->assertSee($voucher)->assertSee('88,611.57')->assertSee('3,611.17');
});

test('salary payment status separates paid in progress and outstanding with each payment workflow status', function () {
    $rows = collect(payrollreportRows('payment-status', $this->auditor));
    $run = $rows->first();
    $posted = $rows->firstWhere('item', 'Payment #'.$this->postedPayment->id.' (Bank Payment)');
    $draft = $rows->firstWhere('item', 'Payment #'.$this->draftPayment->id.' (Bank Payment)');
    $total = $rows->firstWhere('item', 'Total');

    expect($rows)->toHaveCount(4)
        ->and($run)->toMatchArray(['status' => 'Posted', 'net' => '85000.4000', 'paid' => '40000.5000', 'in_progress' => '10000.2500', 'outstanding' => '44999.9000'])
        ->and($posted)->toMatchArray(['status' => 'Posted', 'reference' => 'SAL-REF-1', 'payment' => '40000.5000', 'date' => '31 Jan 2026'])
        ->and($draft)->toMatchArray(['status' => 'Draft', 'reference' => 'SAL-REF-2', 'payment' => '10000.2500'])
        ->and($total)->toMatchArray(['net' => '85000.4000', 'paid' => '40000.5000', 'in_progress' => '10000.2500', 'outstanding' => '44999.9000'])
        ->and(app(PayrollRunService::class)->paidAmount($this->run))->toBe('40000.5000');

    // A rejected payment keeps its row and status but counts for nothing.
    $engine = app(OperationalTransactionService::class);
    $engine->submit($this->draftPayment, $this->maker);
    $engine->reject($this->draftPayment->fresh(), 'Wrong bank account selected', $this->approver);
    $rows = collect(payrollreportRows('payment-status', $this->auditor));
    expect($this->draftPayment->fresh()->status)->toBe(OperationalTransactionStatus::Rejected)
        ->and($rows->firstWhere('reference', 'SAL-REF-2'))->toMatchArray(['status' => 'Rejected', 'payment' => '10000.2500'])
        ->and($rows->first())->toMatchArray(['paid' => '40000.5000', 'in_progress' => '0.0000', 'outstanding' => '44999.9000']);

    $this->get(route('payroll-reports.show', 'payment-status'))->assertOk()->assertSee('SAL-REF-1')->assertSee('40,000.50')->assertSee('44,999.90')->assertSee('Rejected');
});

test('deductions summary totals stored deductions by component and period', function () {
    $rows = collect(payrollreportRows('deductions', $this->auditor));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['period' => 'January 2026', 'code' => 'TAX', 'entries' => '2', 'amount' => '3611.1700'])
        ->and($rows[1])->toMatchArray(['period' => 'Total', 'amount' => '3611.1700'])
        // The default covers posted and paid runs only; other statuses are an explicit choice.
        ->and(payrollreportRows('deductions', $this->auditor, ['status' => 'draft']))->toBe([]);
    $this->get(route('payroll-reports.show', 'deductions'))->assertOk()->assertSee('3,611.17')->assertSee('Amount deducted');
});

test('import validation errors lists row numbers and messages with raw values for sensitive users', function () {
    $import = payrollreportImport($this);

    $list = payrollreportRows('import-errors', $this->auditor);
    expect($list)->toHaveCount(1)->and($list[0])->toMatchArray(['import' => '#'.$import->id, 'file' => 'january-payroll.xlsx', 'status' => 'Validated', 'rows' => '3', 'error_rows' => '1', 'warning_rows' => '1']);

    $rows = collect(payrollreportRows('import-errors', $this->auditor, ['import_id' => $import->id]));
    expect($rows->pluck('row')->all())->toBe(['3', '4'])
        ->and($rows[0])->toMatchArray(['status' => 'Error', 'employee_code' => 'EMP-B', 'employee_name' => 'Employee Bravo', 'gross' => '31111.1100', 'net' => '30000.0000'])
        ->and($rows[0]['errors'])->toContain('(31999.0000)')
        ->and($rows[1])->toMatchArray(['status' => 'Warning', 'employee_code' => 'EMP-X', 'employee_name' => 'Secret Name', 'gross' => '64321.9900', 'errors' => ''])
        ->and($rows[1]['warnings'])->toContain('does not match the employee master');
    $this->get(route('payroll-reports.show', ['report' => 'import-errors', 'import_id' => $import->id]))->assertOk()->assertSee('Secret Name')->assertSee('31999.0000')->assertSee('64,321.99');
});

test('a user without the sensitive permission sees no amount anywhere and is refused amount only reports', function () {
    $import = payrollreportImport($this);
    $this->actingAs($this->viewer);
    $reports = app(PayrollReportService::class);

    foreach (PayrollReportService::AMOUNT_ONLY as $report) {
        $this->get(route('payroll-reports.show', ['report' => $report, 'run_id' => $this->run->id]))->assertForbidden();
        expect(fn () => $reports->build($report, $this->viewer, ['run_id' => $this->run->id]))->toThrow(HttpException::class);
    }

    $pages = [
        route('payroll-reports.index'),
        route('payroll-reports.show', 'run-summary'),
        route('payroll-reports.show', 'accrual-summary'),
        route('payroll-reports.show', 'payment-status'),
        route('payroll-reports.show', ['report' => 'employee-history', 'employee_id' => $this->employeeA->id]),
        route('payroll-reports.show', 'import-errors'),
        route('payroll-reports.show', ['report' => 'import-errors', 'import_id' => $import->id]),
    ];
    foreach ($pages as $page) {
        $html = $this->get($page)->assertOk()->assertSee('Salary amounts are hidden')->getContent();
        foreach (payrollreportAmountStrings() as $amount) {
            expect(str_contains($html, $amount))->toBeFalse("{$amount} leaked on {$page}");
        }
        expect($html)->not->toContain('/export/');
    }

    // The tables themselves carry no amount column and no amount value: nothing is merely hidden by the view.
    foreach (['run-summary' => [], 'accrual-summary' => [], 'payment-status' => [], 'employee-history' => ['employee_id' => $this->employeeA->id], 'import-errors' => ['import_id' => $import->id]] as $report => $input) {
        $table = $reports->build($report, $this->viewer, $input)['table'];
        expect(collect($table->columns)->where('type', 'money')->all())->toBe([])
            ->and($table->rows)->not->toBe([]);
        $serialized = json_encode($table);
        foreach (payrollreportAmountStrings() as $amount) {
            expect(str_contains($serialized, $amount))->toBeFalse("{$amount} leaked in the {$report} table");
        }
        expect($serialized)->not->toContain('Total');
    }

    // Workflow information stays available.
    $this->get(route('payroll-reports.show', 'run-summary'))->assertSee('January 2026')->assertSee('Posted')->assertSee($this->run->journalEntry->voucher_number);
    $this->get(route('payroll-reports.show', 'payment-status'))->assertSee('SAL-REF-1')->assertSee('Draft');

    // Import messages keep their row numbers, but raw cell values and quoted amounts are masked.
    $rows = collect($reports->build('import-errors', $this->viewer, ['import_id' => $import->id])['table']->rows);
    expect($rows->pluck('row')->all())->toBe(['3', '4'])
        ->and($rows[0])->not->toHaveKeys(['employee_code', 'employee_name', 'gross', 'net'])
        ->and($rows[0]['errors'])->toBe('Gross stated in the file ([hidden]) does not equal the gross computed from the mapped components ([hidden]).');
    $this->get(route('payroll-reports.show', ['report' => 'import-errors', 'import_id' => $import->id]))->assertSee('does not match the employee master')->assertDontSee('Secret Name')->assertDontSee('EMP-X');
});

test('payroll reports need payroll view', function () {
    $stranger = User::factory()->create();
    $stranger->givePermissionTo(['payroll.view_sensitive', 'payroll_reports.export', 'reports.view']);
    $stranger->branches()->attach($this->branch);
    $inactive = payrollreportUser(SystemRole::Auditor, $this->branch);
    $inactive->update(['is_active' => false]);

    $this->actingAs($stranger)->get(route('payroll-reports.index'))->assertForbidden();
    $this->get(route('payroll-reports.show', 'run-summary'))->assertForbidden();
    $this->get(route('payroll-reports.export', ['report' => 'run-summary', 'format' => 'csv']))->assertForbidden();
    expect(fn () => app(PayrollReportService::class)->build('run-summary', $stranger, []))->toThrow(HttpException::class)
        ->and(fn () => app(PayrollReportService::class)->build('run-summary', $inactive, []))->toThrow(HttpException::class);
    $this->actingAs($this->auditor)->get('/payroll-reports/unknown-report')->assertNotFound();
});

test('exports are refused without each of the three permissions and for the management role', function () {
    $make = function (array $permissions): User {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->branches()->attach($this->branch);

        return $user;
    };
    $all = ['payroll.view', 'payroll.view_sensitive', 'payroll_reports.export'];
    $service = app(PayrollReportService::class);

    foreach ($all as $missing) {
        $user = $make(array_values(array_diff($all, [$missing])));
        foreach (array_keys(PayrollReportService::REPORTS) as $report) {
            $this->actingAs($user)->get(route('payroll-reports.export', ['report' => $report, 'format' => 'csv', 'run_id' => $this->run->id]))->assertForbidden();
        }
        expect(fn () => $service->exportTable('run-summary', $user, []))->toThrow(HttpException::class);
    }

    // Management reads payroll, including amounts, but holds no export permission.
    expect($this->management->can('payroll.view'))->toBeTrue()
        ->and($this->management->can('payroll.view_sensitive'))->toBeTrue()
        ->and($this->management->can('payroll_reports.export'))->toBeFalse();
    foreach (array_keys(PayrollReportService::REPORTS) as $report) {
        $this->actingAs($this->management)->get(route('payroll-reports.export', ['report' => $report, 'format' => 'csv', 'run_id' => $this->run->id]))->assertForbidden();
    }
    expect(fn () => $service->exportTable('register', $this->management, ['run_id' => $this->run->id]))->toThrow(HttpException::class);
    $this->actingAs($this->management)->get(route('payroll-reports.show', 'run-summary'))->assertOk()->assertSee('88,611.57')->assertDontSee('/export/');

    $this->actingAs($make($all))->get(route('payroll-reports.export', ['report' => 'run-summary', 'format' => 'csv']))->assertOk();
    $this->actingAs($this->auditor)->get(route('payroll-reports.show', 'run-summary'))->assertOk()->assertSee('/export/csv');
    $this->get(route('payroll-reports.export', ['report' => 'run-summary', 'format' => 'docx']))->assertNotFound();
    $this->get(route('payroll-reports.export', ['report' => 'register', 'format' => 'csv']))->assertNotFound();
});

test('another branch is refused and never appears in lists', function () {
    $otherImport = payrollreportImport($this, $this->otherBranch);
    $service = app(PayrollReportService::class);

    foreach (array_keys(PayrollReportService::REPORTS) as $report) {
        $this->get(route('payroll-reports.show', ['report' => $report, 'branch_id' => $this->otherBranch->id]))->assertForbidden();
        $this->get(route('payroll-reports.export', ['report' => $report, 'format' => 'csv', 'branch_id' => $this->otherBranch->id]))->assertForbidden();
    }
    $this->get(route('payroll-reports.show', ['report' => 'register', 'run_id' => $this->otherRun->id]))->assertForbidden();
    $this->get(route('payroll-reports.export', ['report' => 'register', 'format' => 'csv', 'run_id' => $this->otherRun->id]))->assertForbidden();
    $this->get(route('payroll-reports.show', ['report' => 'employee-history', 'employee_id' => $this->employeeC->id]))->assertForbidden();
    $this->get(route('payroll-reports.show', ['report' => 'import-errors', 'import_id' => $otherImport->id]))->assertForbidden();
    expect(fn () => $service->build('register', $this->auditor, ['run_id' => $this->otherRun->id]))->toThrow(HttpException::class);

    // Lists, selectors and totals hold nothing of the other branch.
    foreach (['run-summary' => ['status' => 'draft'], 'run-summary ' => [], 'payment-status' => [], 'accrual-summary' => [], 'deductions' => ['status' => 'draft'], 'import-errors' => [], 'register' => [], 'employee-history' => []] as $report => $query) {
        $this->get(route('payroll-reports.show', ['report' => trim($report), ...$query]))->assertOk()
            ->assertDontSee('Report Other Branch')->assertDontSee('EMP-C')->assertDontSee('77,777.77')->assertDontSee('run #'.$this->otherRun->id)->assertDontSee('— #'.$this->otherRun->id.' (');
    }
    expect(payrollreportRows('run-summary', $this->auditor, ['status' => 'draft']))->toBe([])
        ->and(payrollreportRows('import-errors', $this->auditor))->toBe([]);
    expect(payrollreportCsv($this, 'run-summary'))->not->toContain('77777.77')->not->toContain('Report Other Branch');
});

test('the consolidated all branch view needs branches view all', function () {
    // Without the permission the default is the user's own branches and an explicit "all" is refused.
    $this->get(route('payroll-reports.show', ['report' => 'run-summary', 'branch_id' => 'all']))->assertForbidden();
    $this->get(route('payroll-reports.export', ['report' => 'run-summary', 'format' => 'csv', 'branch_id' => 'all']))->assertForbidden();
    expect(collect(payrollreportRows('run-summary', $this->auditor))->pluck('branch')->filter()->unique()->values()->all())->toBe(['Report Main Branch']);
    $this->get(route('payroll-reports.show', 'run-summary'))->assertSee('All my branches')->assertDontSee('All branches</option>', false);

    $this->actingAs($this->administrator);
    $rows = collect(payrollreportRows('run-summary', $this->administrator, ['branch_id' => 'all']));
    expect($rows->pluck('branch')->filter()->unique()->sort()->values()->all())->toBe(['Report Main Branch', 'Report Other Branch'])
        ->and($rows->firstWhere('period', 'Total — Draft'))->toMatchArray(['gross' => '77777.7700', 'net' => '77777.7700'])
        ->and($rows->firstWhere('period', 'Total — Posted'))->toMatchArray(['gross' => '88611.5700']);
    $this->get(route('payroll-reports.show', ['report' => 'run-summary', 'branch_id' => 'all']))->assertOk()->assertSee('Report Other Branch')->assertSee('77,777.77');
    $this->get(route('payroll-reports.show', ['report' => 'run-summary', 'branch_id' => $this->otherBranch->id]))->assertOk()->assertSee('77,777.77')->assertDontSee('88,611.57');

    // A user assigned to both branches sees both without the consolidated permission, but only those.
    $this->auditor->branches()->attach($this->otherBranch);
    expect(collect(payrollreportRows('run-summary', $this->auditor->fresh()))->pluck('branch')->filter()->unique()->count())->toBe(2);
});

test('exports contain the same exact figures in csv xlsx and pdf and are audited', function () {
    $register = payrollreportCsv($this, 'register', ['run_id' => $this->run->id]);
    expect($register)->toContain('Payroll Register')->toContain('Branch: Report Main Branch')->toContain('Period: January 2026')
        ->and(array_slice(payrollreportCsvRow($register, 'EMP-A'), -3))->toBe(['57500.4600', '2500.0600', '55000.4000'])
        ->and(array_slice(payrollreportCsvRow($register, 'EMP-B'), -3))->toBe(['31111.1100', '1111.1100', '30000.0000'])
        ->and(array_slice(payrollreportCsvRow($register, 'Total'), -3))->toBe(['88611.5700', '3611.1700', '85000.4000'])
        ->and($register)->toContain('50000.1200')->toContain('7500.3400');

    expect(array_slice(payrollreportCsvRow(payrollreportCsv($this, 'run-summary'), 'Total — Posted'), -3))->toBe(['88611.5700', '3611.1700', '85000.4000'])
        ->and(array_slice(payrollreportCsvRow(payrollreportCsv($this, 'accrual-summary'), 'Total'), -3))->toBe(['88611.5700', '85000.4000', '3611.1700'])
        ->and(array_slice(payrollreportCsvRow(payrollreportCsv($this, 'payment-status'), 'Total'), -5))->toBe(['85000.4000', '', '40000.5000', '10000.2500', '44999.9000'])
        ->and(array_slice(payrollreportCsvRow(payrollreportCsv($this, 'deductions'), 'January 2026'), -2))->toBe(['2', '3611.1700'])
        ->and(array_slice(payrollreportCsvRow(payrollreportCsv($this, 'employee-history', ['employee_id' => $this->employeeB->id]), 'January 2026'), -3))->toBe(['31111.1100', '1111.1100', '30000.0000']);
    $import = payrollreportImport($this);
    expect(payrollreportCsv($this, 'import-errors', ['import_id' => $import->id]))->toContain('Secret Name')->toContain('64321.9900')->toContain('(31999.0000)');

    $path = tempnam(sys_get_temp_dir(), 'pay').'.xlsx';
    file_put_contents($path, $this->get(route('payroll-reports.export', ['report' => 'register', 'format' => 'xlsx', 'run_id' => $this->run->id]))->assertOk()->streamedContent());
    $sheet = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false));
    unlink($path);
    $total = $sheet->first(fn (array $row): bool => $row[0] === 'Total');
    expect(array_slice(array_values(array_filter($total, fn (mixed $cell): bool => $cell !== null)), -3))->toEqual([88611.57, 3611.17, 85000.4]);

    $pdf = $this->get(route('payroll-reports.export', ['report' => 'run-summary', 'format' => 'pdf']))->assertOk()->streamedContent();
    expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()->and($pdf)->toContain('(Payroll Run Summary)')->toContain('(88,611.57)')->toContain('(85,000.40)');

    $audit = AuditLog::query()->where('event', 'report.exported')->get();
    expect($audit)->toHaveCount(9)->and($audit->first()->properties['report'])->toBe('Payroll Register')->and($audit->first()->user_id ?? $this->auditor->id)->toBe($this->auditor->id);
});

test('payroll reports never create journals payments or workflow changes', function () {
    $import = payrollreportImport($this);
    $before = [JournalEntry::query()->count(), OperationalTransaction::query()->count(), PayrollRunPayment::query()->count(), PayrollRun::query()->count(), $this->run->fresh()->updated_at->toISOString(), $this->otherRun->fresh()->status];

    foreach (array_keys(PayrollReportService::REPORTS) as $report) {
        $query = ['report' => $report, 'run_id' => $this->run->id, 'employee_id' => $this->employeeA->id, 'import_id' => $import->id];
        $this->get(route('payroll-reports.show', $query))->assertOk();
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->get(route('payroll-reports.export', [...$query, 'format' => $format]))->assertOk()->streamedContent();
        }
    }
    // Reports also work, and still post nothing, while the calculation policy is not approved.
    AppSetting::query()->where('key', 'payroll.calculation_policy_approved')->first()->update(['value' => '0']);
    $this->get(route('payroll-reports.show', 'accrual-summary'))->assertOk()->assertSee('88,611.57');

    expect([JournalEntry::query()->count(), OperationalTransaction::query()->count(), PayrollRunPayment::query()->count(), PayrollRun::query()->count(), $this->run->fresh()->updated_at->toISOString(), $this->otherRun->fresh()->status])->toBe($before)
        ->and($this->run->fresh()->status)->toBe(PayrollRunStatus::Posted)
        ->and($import->fresh()->status)->toBe(PayrollImportStatus::Validated);
});

test('payroll reports add a sidebar entry only for users who may open them', function () {
    expect($this->actingAs($this->management)->get(route('dashboard'))->assertOk()->getContent())->toContain('Payroll Reports')->toContain(route('payroll-reports.index'));
    $plain = User::factory()->create();
    $plain->givePermissionTo('dashboard.view');
    expect($this->actingAs($plain)->get(route('dashboard'))->assertOk()->getContent())->not->toContain('Payroll Reports');
});
