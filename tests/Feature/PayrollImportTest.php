<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\EmploymentStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PayrollImportStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\SystemRole;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\PayrollComponent;
use App\Models\PayrollImport;
use App\Models\PayrollImportRow;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollImportService;
use App\Services\Payroll\PayrollRunService;
use App\Services\Payroll\PayrollWorkbookReader;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->maker = payrollImportUser(SystemRole::Accountant, $this->branch);
    $this->basic = PayrollComponent::query()->where('code', 'BASIC')->firstOrFail();
    $this->advance = PayrollComponent::query()->where('code', 'ADVANCE')->firstOrFail();
    $this->tax = PayrollComponent::query()->where('code', 'TAX')->firstOrFail();
    $this->bonus = PayrollComponent::query()->where('code', 'BONUS')->firstOrFail();
    foreach (['EMP-T001' => 'Test Person One', 'EMP-T002' => 'Test Person Two', 'EMP-T003' => 'Test Person Three'] as $code => $name) {
        Employee::factory()->create(['employee_code' => $code, 'name' => $name, 'branch_id' => $this->branch->id, 'created_by' => $this->maker->id]);
    }
    $this->actingAs($this->maker);
});

function payrollImportUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/**
 * A generated, anonymised workbook: Code | Employee | Gross Salary | Advance Salary | Taxes | Bonus | Net Salary.
 *
 * @param  list<list<mixed>>  $rows
 */
function payrollImportWorkbook(array $rows, bool $preCalculate = true, ?array $headers = null): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Summary')->fromArray([$headers ?? ['Code', 'Employee', 'Gross Salary', 'Advance Salary', 'Taxes', 'Bonus', 'Net Salary'], ...$rows], null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'payroll-test-').'.xlsx';
    (new Xlsx($book))->setPreCalculateFormulas($preCalculate)->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'generated-payroll.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** @return array<string, mixed> */
function payrollImportMapping(object $test, array $overrides = []): array
{
    return [
        'employee_code' => 'A', 'employee_name' => 'B', 'stated_net' => 'G',
        'components' => [$test->basic->id => 'C', $test->advance->id => 'D', $test->tax->id => 'E', $test->bonus->id => 'F'],
        ...$overrides,
    ];
}

/** Upload and map a generated workbook through the service. */
function payrollImportValidated(object $test, array $rows, array $mapping = [], bool $preCalculate = true): PayrollImport
{
    $service = app(PayrollImportService::class);
    $import = $service->upload(payrollImportWorkbook($rows, $preCalculate), $test->branch->id, $test->period->id, $test->maker);

    return $service->applyMapping($import, payrollImportMapping($test, $mapping), $test->maker);
}

/** @return list<string> */
function payrollImportErrors(PayrollImport $import, int $rowNumber): array
{
    return $import->rows()->where('row_number', $rowNumber)->firstOrFail()->errors ?? [];
}

test('full flow: upload, preview, mapping, validation, confirmation creates only a draft run', function () {
    $file = payrollImportWorkbook([
        ['EMP-T001', 'Test Person One', 50000.1234, 1000, 1250.5, 2000, 49749.6234],
        ['EMP-T002', 'Test Person Two', 30000, null, 750, null, 29250],
    ]);
    $this->post(route('payroll-imports.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id, 'workbook' => $file])->assertRedirect();
    $import = PayrollImport::query()->firstOrFail();

    // Nothing is imported at upload.
    expect($import->status)->toBe(PayrollImportStatus::Uploaded)
        ->and($import->header_row)->toBe(1)
        ->and($import->data_start_row)->toBe(2)
        ->and($import->detected_headers)->toBe(['A' => 'Code', 'B' => 'Employee', 'C' => 'Gross Salary', 'D' => 'Advance Salary', 'E' => 'Taxes', 'F' => 'Bonus', 'G' => 'Net Salary'])
        ->and($import->file_hash)->toHaveLength(64)
        ->and(Storage::disk('local')->exists($import->stored_path))->toBeTrue()
        ->and(PayrollImportRow::query()->count())->toBe(0)
        ->and(PayrollRun::query()->count())->toBe(0);

    $this->get(route('payroll-imports.index'))->assertOk()->assertSee('generated-payroll.xlsx');
    $this->get(route('payroll-imports.show', $import))->assertOk()->assertSee('Column mapping')->assertSee('Gross Salary')->assertSee('EMP-T001')->assertSee('50000.1234');

    $this->post(route('payroll-imports.mapping', $import), payrollImportMapping($this))->assertRedirect(route('payroll-imports.report', $import));
    $import->refresh();
    $first = $import->rows()->where('row_number', 2)->first();
    expect($import->status)->toBe(PayrollImportStatus::Validated)
        ->and($import->summary['total_rows'])->toBe(2)
        ->and($import->summary['error_rows'])->toBe(0)
        ->and($first->errors)->toBe([])
        ->and($first->mapped_values['amounts'])->toBe([$this->basic->id => '50000.1234', $this->advance->id => '1000.0000', $this->tax->id => '1250.5000', $this->bonus->id => '2000.0000'])
        ->and($first->mapped_values['gross'])->toBe('52000.1234')
        ->and($first->mapped_values['deductions'])->toBe('2250.5000')
        ->and($first->mapped_values['net'])->toBe('49749.6234')
        ->and($first->getRawOriginal('mapped_values'))->not->toContain('50000.1234')
        ->and(PayrollRun::query()->count())->toBe(0);

    $this->get(route('payroll-imports.report', $import))->assertOk()->assertSee('Authorised confirmation')->assertSee('49,749.62');
    $this->post(route('payroll-imports.confirm', $import))->assertRedirect()->assertSessionHas('status');

    $run = PayrollRun::query()->with('lines.items')->firstOrFail();
    expect($run->status)->toBe(PayrollRunStatus::Draft)
        ->and($run->source)->toBe('import')
        ->and($run->approved_by)->toBeNull()
        ->and($run->journal_entry_id)->toBeNull()
        ->and($run->lines)->toHaveCount(2)
        ->and($run->gross_amount)->toBe('82000.1234')
        ->and($run->total_deductions)->toBe('3000.5000')
        ->and($run->net_amount)->toBe('78999.6234')
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(Employee::query()->count())->toBe(3)
        ->and($import->fresh()->status)->toBe(PayrollImportStatus::Confirmed)
        ->and($import->fresh()->payroll_run_id)->toBe($run->id)
        ->and(AuditLog::query()->where('event', 'payroll_import.confirmed')->exists())->toBeTrue();

    // Confirming twice is refused and creates nothing more.
    $this->post(route('payroll-imports.confirm', $import))->assertSessionHasErrors('status');
    expect(fn () => app(PayrollImportService::class)->confirm($import->fresh(), true, $this->maker))->toThrow(ValidationException::class, 'already confirmed')
        ->and(PayrollRun::query()->count())->toBe(1);
    $this->get(route('payroll-imports.report', $import))->assertOk()->assertSee('created draft payroll run')->assertDontSee('Authorised confirmation');
    $this->get(route('payroll-imports.show', $import))->assertOk()->assertSee('Column mapping used');
});

test('two-row merged headings like the client summary sheet are detected and suggested', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([
        ['Summary for the Month of Test'],
        [],
        ['S. No.', 'Code', 'Employee', 'Total Salary', 'Gross Salary', 'Paid Salaries', null, null, "Advance\nSalary", 'AKKF', 'Taxes', 'Arrears', 'Total Deduction', 'Bonus', "Semester+Yearly\nLeaves"],
        [null, null, null, null, null, 'Gross Salary', 'Net Salary', 'Salary+Akkf+Advance'],
        [1, 'EMP-T001', 'Test Person One', 1000, 1000, 1000, 900, 1000, 0, 0, 100, 0, 100, 0, 0],
    ], null, 'A1', true);
    foreach (['A3:A4', 'B3:B4', 'C3:C4', 'D3:D4', 'E3:E4', 'F3:H3', 'I3:I4', 'J3:J4', 'K3:K4', 'L3:L4', 'M3:M4', 'N3:N4', 'O3:O4', 'A1:I1'] as $range) {
        $sheet->mergeCells($range);
    }
    $path = tempnam(sys_get_temp_dir(), 'payroll-test-').'.xlsx';
    (new Xlsx($book))->save($path);
    $service = app(PayrollImportService::class);
    $import = $service->upload(new UploadedFile($path, 'summary.xlsx', null, null, true), $this->branch->id, $this->period->id, $this->maker);
    $suggested = $service->suggestMapping($import, PayrollComponent::query()->where('is_active', true)->orderBy('sort_order')->get());

    expect($import->header_row)->toBe(3)
        ->and($import->data_start_row)->toBe(5)
        ->and($import->detected_headers['B'])->toBe('Code')
        ->and($import->detected_headers['F'])->toBe('Paid Salaries / Gross Salary')
        ->and($import->detected_headers['G'])->toBe('Paid Salaries / Net Salary')
        ->and($import->detected_headers['I'])->toBe('Advance Salary')
        ->and($import->detected_headers['O'])->toBe('Semester+Yearly Leaves')
        ->and($suggested['employee_code'])->toBe('B')
        ->and($suggested['employee_name'])->toBe('C')
        ->and($suggested['stated_net'])->toBe('G')
        ->and($suggested['stated_gross'])->toBeNull()
        ->and($suggested['components'][$this->basic->id])->toBe('E')
        ->and($suggested['components'][$this->advance->id])->toBe('I')
        ->and($suggested['components'][$this->tax->id])->toBe('K')
        ->and($suggested['components'][$this->bonus->id])->toBe('N')
        ->and($suggested['components'][PayrollComponent::query()->where('code', 'ARREARS')->value('id')])->toBe('L')
        // No statutory or leave component exists, so nothing is invented for AKKF or leaves.
        ->and(array_values($suggested['components']))->not->toContain('J', 'O', 'M', 'D', 'H');
});

test('column mapping is validated', function () {
    $service = app(PayrollImportService::class);
    $import = $service->upload(payrollImportWorkbook([['EMP-T001', 'Test Person One', 1000, 0, 0, 0, 1000]]), $this->branch->id, $this->period->id, $this->maker);
    $inactive = PayrollComponent::factory()->create(['is_active' => false]);

    expect(fn () => $service->applyMapping($import, ['employee_code' => null, 'components' => [$this->basic->id => 'C']], $this->maker))->toThrow(ValidationException::class, 'employee code')
        ->and(fn () => $service->applyMapping($import, ['employee_code' => 'A', 'components' => []], $this->maker))->toThrow(ValidationException::class, 'at least one payroll component')
        ->and(fn () => $service->applyMapping($import, ['employee_code' => 'A', 'components' => [$this->basic->id => 'Z']], $this->maker))->toThrow(ValidationException::class, 'does not exist')
        ->and(fn () => $service->applyMapping($import, ['employee_code' => 'A', 'components' => [$this->basic->id => 'A']], $this->maker))->toThrow(ValidationException::class, 'only once')
        ->and(fn () => $service->applyMapping($import, ['employee_code' => 'A', 'components' => [$inactive->id => 'C']], $this->maker))->toThrow(ValidationException::class, 'active payroll components')
        ->and($import->fresh()->status)->toBe(PayrollImportStatus::Uploaded);
    $this->post(route('payroll-imports.mapping', $import), ['components' => [$this->basic->id => 'C']])->assertSessionHasErrors('employee_code');
});

test('duplicate, missing and unknown employee codes are row errors and employees are never created', function () {
    Employee::factory()->create(['employee_code' => 'EMP-OTHER', 'branch_id' => $this->otherBranch->id, 'created_by' => $this->maker->id]);
    Employee::query()->where('employee_code', 'EMP-T003')->update(['employment_status' => EmploymentStatus::Inactive->value]);
    $import = payrollImportValidated($this, [
        ['EMP-T001', 'Test Person One', 1000, 0, 0, 0, 1000],
        ['emp-t001', 'Test Person One', 1000, 0, 0, 0, 1000],
        [null, 'Nameless Row', 1000, 0, 0, 0, 1000],
        ['EMP-GHOST', 'Unknown Person', 1000, 0, 0, 0, 1000],
        ['EMP-OTHER', 'Other Branch Person', 1000, 0, 0, 0, 1000],
        ['EMP-T003', 'Test Person Three', 1000, 0, 0, 0, 1000],
        ['EMP-T002', 'A Different Name', 1000, 0, 0, 0, 1000],
    ]);

    expect(payrollImportErrors($import, 2))->toContain('Duplicate employee code in the file.')
        ->and(payrollImportErrors($import, 3))->toContain('Duplicate employee code in the file.')
        ->and(payrollImportErrors($import, 4))->toContain('Missing employee code.')
        ->and(payrollImportErrors($import, 5)[0])->toContain('Unknown employee')
        ->and(payrollImportErrors($import, 6)[0])->toContain('Unknown employee')
        ->and(payrollImportErrors($import, 7))->toContain('The employee is not active.')
        ->and(payrollImportErrors($import, 8))->toBe([])
        ->and($import->rows()->where('row_number', 8)->first()->warnings)->toContain('The employee name in the file does not match the employee master.')
        ->and($import->summary['error_rows'])->toBe(6)
        ->and($import->summary['warning_rows'])->toBe(1)
        ->and(Employee::query()->where('employee_code', 'EMP-GHOST')->exists())->toBeFalse()
        ->and(Employee::query()->count())->toBe(4);
});

test('invalid, negative, over-precise and inconsistent amounts are row errors', function () {
    $import = payrollImportValidated($this, [
        ['EMP-T001', 'Test Person One', 'abc', 0, 0, 0, null],
        ['EMP-T002', 'Test Person Two', 1000, -50, 0, 0, null],
        ['EMP-T003', 'Test Person Three', 1000.12345, 0, 0, 0, null],
        ['EMP-T001', 'Test Person One', 1000, 100, 50, 0, 900],
        ['EMP-T002', 'Test Person Two', null, null, null, null, null],
        ['EMP-T003', 'Test Person Three', 100, 200, 0, 0, null],
        ['EMP-T003', 'Test Person Three', 100, null, null, null, 100],
    ], ['stated_gross' => null, 'required' => [$this->tax->id]]);

    expect(payrollImportErrors($import, 2))->toContain('Gross Salary: the value is not a valid number.')
        ->and(payrollImportErrors($import, 3))->toContain('Advance Salary: negative amounts are not allowed.')
        ->and(payrollImportErrors($import, 4))->toContain('Gross Salary: amounts may have at most four decimals.')
        ->and(collect(payrollImportErrors($import, 5))->contains(fn (string $error) => str_contains($error, 'Net stated in the file (900.0000) does not equal the net computed from the mapped components (850.0000)')))->toBeTrue()
        ->and(payrollImportErrors($import, 6))->toContain('No mapped payroll component has a value in this row.')
        ->and(payrollImportErrors($import, 7))->toContain('Net pay is negative: deductions exceed earnings.')
        ->and(payrollImportErrors($import, 8))->toContain('Income Tax: a value is required by the column mapping but the cell is empty.')
        ->and($import->rows()->where('row_number', 3)->first()->mapped_values['amounts'])->not->toHaveKey($this->advance->id)
        ->and(fn () => app(PayrollImportService::class)->confirm($import, true, $this->maker))->toThrow(ValidationException::class, 'still have errors')
        ->and(PayrollRun::query()->count())->toBe(0);
});

test('stated gross and an optional date column are checked', function () {
    $headers = ['Code', 'Employee', 'Gross Salary', 'Advance Salary', 'Taxes', 'Bonus', 'Net Salary', 'Total Earnings', 'Pay Date'];
    $service = app(PayrollImportService::class);
    $import = $service->upload(payrollImportWorkbook([
        ['EMP-T001', 'Test Person One', 1000, 0, 100, 500, 1400, 1500, '2026-01-31'],
        ['EMP-T002', 'Test Person Two', 1000, 0, 100, 500, 1400, 1600, 'not a date'],
        ['EMP-T003', 'Test Person Three', 1000, 0, 100, 500, 1400, 1500, '31/01/2026'],
    ], true, $headers), $this->branch->id, $this->period->id, $this->maker);
    $import = $service->applyMapping($import, payrollImportMapping($this, ['stated_gross' => 'H', 'pay_date' => 'I']), $this->maker);

    expect(payrollImportErrors($import, 2))->toBe([])
        ->and($import->rows()->where('row_number', 2)->first()->mapped_values['pay_date'])->toBe('2026-01-31')
        ->and(collect(payrollImportErrors($import, 3))->contains(fn (string $error) => str_contains($error, 'Gross stated in the file (1600.0000) does not equal the gross computed from the mapped components (1500.0000)')))->toBeTrue()
        ->and(payrollImportErrors($import, 3))->toContain('Pay Date: the value is not a valid date.')
        ->and($import->rows()->where('row_number', 4)->first()->mapped_values['pay_date'])->toBe('2026-01-31');
});

test('formula cells are warnings that must be acknowledged before confirmation', function () {
    $import = payrollImportValidated($this, [
        ['EMP-T001', 'Test Person One', 1000, 100, 50, 0, '=C2-D2-E2+F2'],
        ['EMP-T002', 'Test Person Two', '=500+250', 0, 0, 0, 750],
    ]);
    $first = $import->rows()->where('row_number', 2)->first();
    $second = $import->rows()->where('row_number', 3)->first();
    $service = app(PayrollImportService::class);

    expect($first->errors)->toBe([])
        ->and($first->warnings)->toContain('Net Salary: computed in the spreadsheet — verify.')
        ->and($first->has_formula_warning)->toBeTrue()
        ->and($first->formula_cells)->toBe([['column' => 'G', 'unsafe' => false]])
        ->and($second->warnings)->toContain('Gross Salary: computed in the spreadsheet — verify.')
        ->and($second->mapped_values['amounts'][$this->basic->id])->toBe('750.0000')
        ->and($import->summary['formula_warning_rows'])->toBe(2)
        ->and(fn () => $service->confirm($import, false, $this->maker))->toThrow(ValidationException::class, 'computed by spreadsheet formulas');
    $this->post(route('payroll-imports.confirm', $import), ['acknowledge_formulas' => '0'])->assertSessionHasErrors('acknowledge_formulas');
    $this->get(route('payroll-imports.report', $import))->assertOk()->assertSee('I have verified the 2 row(s)');
    expect(PayrollRun::query()->count())->toBe(0);

    $this->post(route('payroll-imports.confirm', $import), ['acknowledge_formulas' => '1'])->assertRedirect();
    expect(PayrollRun::query()->firstOrFail()->net_amount)->toBe('1600.0000')
        ->and(AuditLog::query()->where('event', 'payroll_import.confirmed')->first()->properties['formula_warnings_acknowledged'])->toBeTrue();
});

test('volatile formulas are errors and are never evaluated', function () {
    $reader = app(PayrollWorkbookReader::class);
    expect($reader->isUnsafeFormula('=RANDBETWEEN(1,9)'))->toBeTrue()
        ->and($reader->isUnsafeFormula('=round(rand()*100,0)'))->toBeTrue()
        ->and($reader->isUnsafeFormula('=IF(TODAY()>1,C2,0)'))->toBeTrue()
        ->and($reader->isUnsafeFormula('=SUM(OFFSET(A1,0,0,2,1))'))->toBeTrue()
        ->and($reader->isUnsafeFormula('=INDIRECT("C"&2)'))->toBeTrue()
        ->and($reader->isUnsafeFormula('=NOW()'))->toBeTrue()
        ->and($reader->isUnsafeFormula('=C2-D2'))->toBeFalse()
        ->and($reader->isUnsafeFormula('=CONCAT("RAND(",A1)'))->toBeFalse();

    // Saved without calculated results: any amount could only come from executing the formula.
    $import = payrollImportValidated($this, [
        ['EMP-T001', 'Test Person One', '=RANDBETWEEN(1,9)', 0, 0, 0, null],
        ['EMP-T002', 'Test Person Two', 1000, 0, 0, 0, '=RANDBETWEEN(1000,1000)'],
        ['EMP-T003', 'Test Person Three', 1000, 0, 0, 0, null],
    ], [], false);
    $first = $import->rows()->where('row_number', 2)->first();
    $second = $import->rows()->where('row_number', 3)->first();

    expect(collect($first->errors)->contains(fn (string $error) => str_contains($error, 'Gross Salary: the cell uses a volatile or indirect spreadsheet function')))->toBeTrue()
        ->and($first->mapped_values['amounts'])->toBe([])
        ->and($first->mapped_values['gross'])->toBe('0.0000')
        ->and($first->formula_cells)->toBe([['column' => 'C', 'unsafe' => true]])
        ->and($first->has_formula_warning)->toBeFalse()
        ->and(collect($second->errors)->contains(fn (string $error) => str_contains($error, 'Net Salary: the cell uses a volatile')))->toBeTrue()
        ->and($second->mapped_values['stated_net'])->toBeNull()
        ->and($import->rows()->where('row_number', 4)->first()->errors)->toBe([])
        ->and(fn () => app(PayrollImportService::class)->confirm($import, true, $this->maker))->toThrow(ValidationException::class, 'still have errors');
    $this->get(route('payroll-imports.show', $import))->assertOk()->assertSee('Volatile formula — not read');

    // Even when the file carries a stored result for the volatile cell, it is not used.
    $withCache = payrollImportValidated($this, [['EMP-T001', 'Test Person One', '=RANDBETWEEN(7,7)', 0, 0, 0, null]]);
    expect($withCache->rows()->first()->mapped_values['amounts'])->toBe([])
        ->and($withCache->rows()->first()->errors)->not->toBe([]);
});

test('a second payroll for the same branch and period is refused', function () {
    $rows = [['EMP-T001', 'Test Person One', 1000, 0, 0, 0, 1000]];
    $service = app(PayrollImportService::class);
    $first = payrollImportValidated($this, $rows);
    $second = payrollImportValidated($this, $rows);
    $service->confirm($first, true, $this->maker);

    expect(fn () => $service->confirm($second, true, $this->maker))->toThrow(ValidationException::class, 'Duplicate payroll')
        ->and(PayrollRun::query()->count())->toBe(1)
        ->and($second->fresh()->status)->toBe(PayrollImportStatus::Validated);

    $third = payrollImportValidated($this, $rows);
    expect($third->summary['import_errors'])->toContain('Duplicate payroll: a payroll run already exists for this branch and period.');
    $this->get(route('payroll-imports.report', $third))->assertOk()->assertSee('Duplicate payroll')->assertSee('Confirmation is not possible yet');

    // A discarded run frees the period again.
    app(PayrollRunService::class)->discard(PayrollRun::query()->first(), 'Prepared from the wrong file', $this->maker);
    expect($service->confirm($third->fresh(), true, $this->maker)->status)->toBe(PayrollRunStatus::Draft);
});

test('confirmation is refused with errors and succeeds after bad rows are excluded', function () {
    $import = payrollImportValidated($this, [
        ['EMP-T001', 'Test Person One', 1000, 0, 100, 0, 900],
        ['EMP-T001', 'Test Person One', 2000, 0, 0, 0, 2000],
        ['EMP-GHOST', 'Unknown Person', 3000, 0, 0, 0, 3000],
        [null, 'Total', 6000, 0, 100, 0, 5900],
        ['EMP-T002', 'Test Person Two', 4000, 0, 0, 0, 4000],
    ]);
    $service = app(PayrollImportService::class);
    $this->post(route('payroll-imports.confirm', $import))->assertSessionHasErrors('rows');
    expect($import->summary['error_rows'])->toBe(4)->and(PayrollRun::query()->count())->toBe(0);

    $bad = $import->rows()->whereIn('row_number', [3, 4, 5])->pluck('id')->all();
    $this->post(route('payroll-imports.exclude', $import), ['rows' => $bad, 'action' => 'exclude'])->assertRedirect(route('payroll-imports.report', $import));
    $import->refresh();

    // Excluding one of two duplicates clears the duplicate error on the remaining row.
    expect($import->summary['excluded_rows'])->toBe(3)
        ->and($import->summary['error_rows'])->toBe(0)
        ->and(payrollImportErrors($import, 2))->toBe([])
        ->and($import->rows()->where('row_number', 4)->first()->is_excluded)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'payroll_import.rows_excluded')->exists())->toBeTrue();

    // Returning a bad row blocks confirmation again.
    $service->setExcluded($import, [$import->rows()->where('row_number', 4)->value('id')], false, $this->maker);
    expect(fn () => $service->confirm($import->fresh(), true, $this->maker))->toThrow(ValidationException::class);
    $service->setExcluded($import->fresh(), [$import->rows()->where('row_number', 4)->value('id')], true, $this->maker);

    $run = $service->confirm($import->fresh(), false, $this->maker);
    expect($run->status)->toBe(PayrollRunStatus::Draft)
        ->and($run->lines()->count())->toBe(2)
        ->and($run->net_amount)->toBe('4900.0000')
        ->and($run->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(fn () => $service->setExcluded($import->fresh(), [1], true, $this->maker))->toThrow(ValidationException::class);
});

test('error report is downloadable as csv without amounts and guards against formula injection', function () {
    $service = app(PayrollImportService::class);
    $csv = "Code,Employee,Gross Salary,Advance Salary,Taxes,Bonus,Net Salary\nEMP-T001,Test Person One,\"12,345.50\",0,0,0,12345.5\n+SUM(1),Injected,55555,0,0,0,55555\nEMP-T002,Test Person Two,abc,0,0,0,\n";
    $import = $service->upload(UploadedFile::fake()->createWithContent('generated.csv', $csv), $this->branch->id, $this->period->id, $this->maker);
    $import = $service->applyMapping($import, payrollImportMapping($this), $this->maker);

    expect($import->rows()->where('row_number', 2)->first()->mapped_values['amounts'][$this->basic->id])->toBe('12345.5000')
        ->and(payrollImportErrors($import, 2))->toBe([]);

    $response = $this->get(route('payroll-imports.export', $import))->assertOk();
    $content = $response->streamedContent();
    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($content)->toContain('Row,"Employee Code",Status,Errors,Warnings')
        ->and($content)->toContain("'+SUM(1)")
        ->and($content)->toContain('Unknown employee')
        ->and($content)->toContain('Gross Salary: the value is not a valid number.')
        ->and($content)->not->toContain('55555')
        ->and($content)->not->toContain('12345')
        ->and(AuditLog::query()->where('event', 'payroll_import.report_exported')->exists())->toBeTrue();

    // The same file uploaded over HTTP as csv.
    $this->post(route('payroll-imports.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id, 'workbook' => UploadedFile::fake()->createWithContent('second.csv', $csv)])->assertRedirect();
    expect(PayrollImport::query()->count())->toBe(2);
});

test('upload validates the file, the branch and the period', function () {
    $valid = fn () => payrollImportWorkbook([['EMP-T001', 'Test Person One', 1000, 0, 0, 0, 1000]]);
    $base = ['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id];

    $this->post(route('payroll-imports.store'), [...$base, 'workbook' => UploadedFile::fake()->create('payroll.pdf', 10, 'application/pdf')])->assertSessionHasErrors('workbook');
    $this->post(route('payroll-imports.store'), [...$base, 'workbook' => UploadedFile::fake()->create('payroll.xlsx', 6000, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])->assertSessionHasErrors('workbook');
    $this->post(route('payroll-imports.store'), [...$base, 'workbook' => UploadedFile::fake()->createWithContent('payroll.xlsx', 'this is not a workbook')])->assertSessionHasErrors('workbook');
    $this->post(route('payroll-imports.store'), [...$base, 'workbook' => UploadedFile::fake()->createWithContent('empty.csv', "1,2,3\n4,5,6\n")])->assertSessionHasErrors('workbook');
    $this->post(route('payroll-imports.store'), ['accounting_period_id' => $this->period->id, 'workbook' => $valid()])->assertSessionHasErrors('branch_id');
    $this->post(route('payroll-imports.store'), [...$base, 'branch_id' => $this->otherBranch->id, 'workbook' => $valid()])->assertForbidden();

    $this->period->update(['status' => AccountingPeriodStatus::Closed]);
    $this->post(route('payroll-imports.store'), [...$base, 'workbook' => $valid()])->assertSessionHasErrors('accounting_period_id');

    expect(PayrollImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('payroll-imports'))->toBe([]);
});

test('imports of another branch and unauthorized users are rejected', function () {
    $import = payrollImportValidated($this, [['EMP-T001', 'Test Person One', 1000, 0, 0, 0, 1000]]);
    $outsider = payrollImportUser(SystemRole::Accountant, $this->otherBranch);
    $approver = payrollImportUser(SystemRole::Approver, $this->branch);
    $service = app(PayrollImportService::class);

    $this->actingAs($outsider)->get(route('payroll-imports.index'))->assertOk()->assertDontSee('generated-payroll.xlsx');
    $this->actingAs($outsider)->get(route('payroll-imports.show', $import))->assertForbidden();
    $this->actingAs($outsider)->get(route('payroll-imports.report', $import))->assertForbidden();
    $this->actingAs($outsider)->get(route('payroll-imports.export', $import))->assertForbidden();
    $this->actingAs($outsider)->post(route('payroll-imports.mapping', $import), payrollImportMapping($this))->assertForbidden();
    $this->actingAs($outsider)->post(route('payroll-imports.confirm', $import))->assertForbidden();
    $this->actingAs($outsider)->post(route('payroll-imports.exclude', $import), ['rows' => [$import->rows()->value('id')], 'action' => 'exclude'])->assertForbidden();
    $this->actingAs($outsider)->post(route('payroll-imports.cancel', $import))->assertForbidden();

    $this->actingAs($approver)->get(route('payroll-imports.index'))->assertForbidden();
    $this->actingAs($approver)->get(route('payroll-imports.report', $import))->assertForbidden();
    $this->actingAs($approver)->post(route('payroll-imports.confirm', $import))->assertForbidden();

    expect(fn () => $service->confirm($import, true, $approver))->toThrow(HttpException::class)
        ->and(fn () => $service->confirm($import, true, $outsider))->toThrow(HttpException::class)
        ->and(fn () => $service->upload(payrollImportWorkbook([['EMP-T001', 'X', 1, 0, 0, 0, 1]]), $this->branch->id, $this->period->id, $approver))->toThrow(HttpException::class)
        ->and(PayrollRun::query()->count())->toBe(0)
        ->and($import->fresh()->status)->toBe(PayrollImportStatus::Validated);
});

test('spreadsheet values are hidden from an importer without the sensitive permission', function () {
    $import = payrollImportValidated($this, [['EMP-T001', 'Test Person One', 43210.5, 0, 0, 0, 43210.5]]);
    $importer = User::factory()->create();
    $importer->givePermissionTo('payroll.import');
    $importer->branches()->attach($this->branch);

    $this->actingAs($importer)->get(route('payroll-imports.show', $import))->assertOk()->assertSee('Gross Salary')->assertSee('Cell values hidden')->assertDontSee('43210.5');
    $this->actingAs($importer)->get(route('payroll-imports.report', $import))->assertOk()->assertSee('EMP-T001')->assertDontSee('43,210.50');
    $this->actingAs($this->maker)->get(route('payroll-imports.report', $import))->assertOk()->assertSee('43,210.50');
});

test('cancelling an import removes its file and rows', function () {
    $import = payrollImportValidated($this, [['EMP-T001', 'Test Person One', 1000, 0, 0, 0, 1000]]);
    $path = $import->stored_path;
    $this->post(route('payroll-imports.cancel', $import))->assertRedirect(route('payroll-imports.index'));

    expect($import->fresh()->status)->toBe(PayrollImportStatus::Cancelled)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($import->rows()->count())->toBe(0)
        ->and(fn () => app(PayrollImportService::class)->confirm($import->fresh(), true, $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => app(PayrollImportService::class)->applyMapping($import->fresh(), payrollImportMapping($this), $this->maker))->toThrow(ValidationException::class);
    $this->get(route('payroll-imports.report', $import))->assertRedirect(route('payroll-imports.show', $import));
    $this->get(route('payroll-imports.show', $import))->assertOk();
});
