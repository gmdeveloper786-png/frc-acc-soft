<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceImport;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\JournalPostingService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalanceImportService;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use App\Services\TrialBalanceService;
use Brick\Math\BigDecimal;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Qa\QaMysqlGuard;

const LIMIT_MAX = '999999999999999.9999';
const LIMIT_ABOVE = '1000000000000000.0000';

// SQLite (the default test database) keeps a decimal as a float, so values
// with more than about 14 significant digits do not read back exactly. These
// tests check the limit at validation time; exact storage and posting at the
// limit need MySQL DECIMAL and run only against a *_mysql_test database.

beforeEach(function () {
    Storage::fake('local');
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create();
    $limitUser = function (SystemRole $role): User {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->branches()->attach($this->branch);

        return $user;
    };
    $this->maker = $limitUser(SystemRole::Accountant);
    $this->director = $limitUser(SystemRole::Director);
    $this->approver = $limitUser(SystemRole::Approver);
    $this->poster = $limitUser(SystemRole::Administrator);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->expense = Account::factory()->create(['code' => '511001', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->income = Account::factory()->create(['code' => '411001', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->assetA = Account::factory()->create(['code' => '11102']);
    $this->assetB = Account::factory()->create(['code' => '11103']);
    $this->equityA = Account::factory()->create(['code' => '31101', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $this->equityB = Account::factory()->create(['code' => '31102', 'account_type' => 'equity', 'normal_balance' => 'credit']);
});

/** @param list<array{0:int,1:string,2:string}> $lines account id, debit, credit */
function limitJournal(object $test, array $lines): array
{
    return [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id, 'branch_id' => $test->branch->id, 'narration' => 'Amount limit fixture',
        'lines' => array_map(fn (array $line) => ['account_id' => $line[0], 'debit' => $line[1], 'credit' => $line[2]], $lines),
    ];
}

function limitOpening(object $test, array $lines): array
{
    return ['fiscal_year_id' => $test->year->id, 'branch_id' => $test->branch->id, 'opening_date' => '2026-01-01',
        'lines' => array_map(fn (array $line) => ['account_id' => $line[0], 'debit' => $line[1], 'credit' => $line[2]], $lines)];
}

function rawCsvUpload(string $body): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'limit-').'.csv';
    file_put_contents($path, "Account Code,Debit,Credit,Source Reference\n".$body);

    return new UploadedFile($path, 'limit.csv', 'text/csv', null, true);
}

function previewRows(object $test, UploadedFile $file): array
{
    $service = app(OpeningBalanceImportService::class);
    $import = $service->preview($file, ['fiscal_year_id' => $test->year->id, 'branch_id' => $test->branch->id, 'opening_date' => '2026-01-01'], $test->maker);
    $display = $service->displayAmounts($import)['rows'];

    return $import->rows->mapWithKeys(fn ($row) => [$row->account_code => ['debit' => $display[$row->row_number]['debit'], 'credit' => $display[$row->row_number]['credit'], 'errors' => $row->validation_errors]])->all();
}

test('the ceiling is the journal column capacity and is compared exactly', function () {
    expect(AccountingDecimal::MAX_AMOUNT)->toBe(LIMIT_MAX)
        ->and(AccountingDecimal::exceedsMaximum(LIMIT_MAX))->toBeFalse()
        ->and(AccountingDecimal::exceedsMaximum('999999999999999.99990000'))->toBeFalse()
        ->and(AccountingDecimal::exceedsMaximum('999999999999999.99991'))->toBeTrue()
        ->and(AccountingDecimal::exceedsMaximum(LIMIT_ABOVE))->toBeTrue();
});

test('manual journals accept the exact maximum and four decimal places and reject anything larger', function () {
    $this->actingAs($this->maker);

    $this->post(route('journals.store'), limitJournal($this, [[$this->expense->id, LIMIT_MAX, '0'], [$this->income->id, '0', LIMIT_MAX]]))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('journals.store'), limitJournal($this, [[$this->expense->id, '123456789.1234', '0'], [$this->income->id, '0', '123456789.1234']]))->assertRedirect()->assertSessionHasNoErrors();
    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalLine::query()->where('journal_entry_id', JournalEntry::query()->max('id'))->orderBy('line_number')->pluck('debit')->all())->toBe(['123456789.1234', '0.0000']);

    $journals = JournalEntry::query()->count();
    $lines = JournalLine::query()->count();
    foreach ([LIMIT_ABOVE, '999999999999999.99991', '99999999999999999999.00', '-5.00', '1.12345'] as $bad) {
        $this->post(route('journals.store'), limitJournal($this, [[$this->expense->id, $bad, '0'], [$this->income->id, '0', $bad]]))->assertSessionHasErrors('lines.0.debit');
    }
    expect(JournalEntry::query()->count())->toBe($journals)->and(JournalLine::query()->count())->toBe($lines);
});

test('balanced journals whose totals overflow are rejected before any line is written', function () {
    $half = '600000000000000.0000';
    $payload = limitJournal($this, [[$this->expense->id, $half, '0'], [$this->income->id, $half, '0'], [$this->expense->id, '0', $half], [$this->income->id, '0', $half]]);

    $this->actingAs($this->maker)->post(route('journals.store'), $payload)->assertSessionHasErrors('lines');
    expect(fn () => app(JournalWorkflowService::class)->create($payload, $this->maker))->toThrow(ValidationException::class)
        ->and(JournalEntry::query()->count())->toBe(0)->and(JournalLine::query()->count())->toBe(0);
});

test('posting refuses an approved journal whose stored lines were altered so their totals exceed the limit', function () {
    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($this->maker);
    $journal = $workflow->create(limitJournal($this, [[$this->expense->id, '100.0000', '0'], [$this->income->id, '0', '100.0000']]), $this->maker);
    $workflow->submit($journal, $this->maker);
    $workflow->approve($journal->fresh(), $this->approver);

    // Malformed stored data that any database can hold: every line fits DECIMAL(19,4) and is
    // within the maximum on its own, but the debit and credit totals (1.2 quadrillion) exceed it.
    $half = '600000000000000.0000';
    DB::table('journal_lines')->where('journal_entry_id', $journal->id)->where('line_number', 1)->update(['debit' => $half]);
    DB::table('journal_lines')->where('journal_entry_id', $journal->id)->where('line_number', 2)->update(['credit' => $half]);
    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $journal->id, 'line_number' => 3, 'account_id' => $this->expense->id, 'debit' => $half, 'credit' => '0.0000', 'created_at' => now(), 'updated_at' => now()],
        ['journal_entry_id' => $journal->id, 'line_number' => 4, 'account_id' => $this->income->id, 'debit' => '0.0000', 'credit' => $half, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(fn () => app(JournalPostingService::class)->post($journal->fresh(), $this->poster))->toThrow(ValidationException::class, 'cannot exceed')
        ->and($journal->fresh()->status)->toBe(JournalStatus::Approved)
        ->and($journal->fresh()->voucher_number)->toBeNull()
        // The oversized aggregate was never written to the header.
        ->and($journal->fresh()->total_debit)->toBe('100.0000')->and($journal->fresh()->total_credit)->toBe('100.0000');
});

test('a journal at the maximum posts exactly and the trial balance reports it without distortion', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Exact DECIMAL(19,4) storage at the limit needs MySQL: run tests/Qa/run-mysql-pest.php.');
    }
    // Proof that this ran on the allowlisted MySQL database, not a silent fallback.
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBe(QaMysqlGuard::PEST_DATABASE)
        // information_schema names its columns in upper case on MySQL 8+, so alias them explicitly.
        ->and((array) DB::selectOne("select lower(data_type) as type_name, numeric_precision as digits, numeric_scale as places from information_schema.columns where table_schema = database() and table_name = 'journal_lines' and column_name = 'debit'"))
        ->toEqual(['type_name' => 'decimal', 'digits' => 19, 'places' => 4]);

    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($this->maker);
    $journal = $workflow->create(limitJournal($this, [[$this->expense->id, LIMIT_MAX, '0'], [$this->income->id, '0', LIMIT_MAX]]), $this->maker);
    $workflow->submit($journal, $this->maker);
    $workflow->approve($journal->fresh(), $this->director);
    $posted = app(JournalPostingService::class)->post($journal->fresh(), $this->poster);
    $trial = app(TrialBalanceService::class)->report($this->year->id, '2026-01-31', [$this->branch->id]);

    // Read back without model casts: the database itself holds the exact figures.
    expect(DB::table('journal_lines')->where('journal_entry_id', $posted->id)->orderBy('line_number')->get(['debit', 'credit'])->map(fn ($line) => [(string) $line->debit, (string) $line->credit])->all())
        ->toBe([[LIMIT_MAX, '0.0000'], ['0.0000', LIMIT_MAX]])
        ->and((string) DB::table('journal_entries')->where('id', $posted->id)->value('total_debit'))->toBe(LIMIT_MAX)
        ->and($posted->total_debit)->toBe(LIMIT_MAX)->and($posted->total_credit)->toBe(LIMIT_MAX)
        ->and($trial['balanced'])->toBeTrue()->and($trial['total_debit'])->toBe(LIMIT_MAX)->and($trial['total_credit'])->toBe(LIMIT_MAX);

    // One unit above is refused by validation, with no database error and nothing stored.
    $journals = JournalEntry::query()->count();
    $this->actingAs($this->maker)->post(route('journals.store'), limitJournal($this, [[$this->expense->id, LIMIT_ABOVE, '0'], [$this->income->id, '0', LIMIT_ABOVE]]))
        ->assertSessionHasErrors('lines.0.debit');
    expect(JournalEntry::query()->count())->toBe($journals);

    // The two-place opening balance maximum is stored exactly as well.
    $batch = app(OpeningBalanceService::class)->saveDraft(limitOpening($this, [[$this->assetA->id, '999999999999999.99', '0'], [$this->equityA->id, '0', '999999999999999.99']]), $this->maker);
    expect((string) DB::table('opening_balance_batches')->where('id', $batch->id)->value('total_debit'))->toBe('999999999999999.99')
        ->and(DB::table('opening_balance_lines')->where('opening_balance_batch_id', $batch->id)->orderBy('account_id')->pluck('debit')->map(fn ($value) => (string) $value)->all())->toContain('999999999999999.99');
});

test('opening balance drafts accept the two decimal maximum and reject larger lines and overflowing totals', function () {
    $this->actingAs($this->maker);
    $max = '999999999999999.99';

    $this->post(route('opening-balances.store'), limitOpening($this, [[$this->assetA->id, $max, '0'], [$this->equityA->id, '0', $max]]))->assertRedirect()->assertSessionHasNoErrors();
    expect(OpeningBalanceBatch::query()->count())->toBe(1);

    $this->post(route('opening-balances.store'), limitOpening($this, [[$this->assetA->id, '1000000000000000.00', '0'], [$this->equityA->id, '0', '1000000000000000.00']]))->assertSessionHasErrors('lines.0.debit');
    $half = '600000000000000.00';
    $overflow = limitOpening($this, [[$this->assetA->id, $half, '0'], [$this->assetB->id, $half, '0'], [$this->equityA->id, '0', $half], [$this->equityB->id, '0', $half]]);
    $this->post(route('opening-balances.store'), $overflow)->assertSessionHasErrors('lines');
    expect(fn () => app(OpeningBalanceService::class)->saveDraft($overflow, $this->maker))->toThrow(ValidationException::class, 'cannot exceed')
        ->and(OpeningBalanceBatch::query()->count())->toBe(1);
});

test('opening balance csv and xlsx imports reject oversized rows and overflowing files without rounding', function () {
    $rows = previewRows($this, rawCsvUpload("11102,999999999999999.99,0,\n11103,1000000000000000.00,0,\n31101,0,99999999999999999999,\n31102,0,1E15,\n"));
    expect($rows['11102']['errors'])->toBe([])->and($rows['11102']['debit'])->toBe('999999999999999.99')
        ->and($rows['11103']['errors'])->toContain(AccountingDecimal::maximumMessage())
        ->and($rows['31101']['errors'])->toContain(AccountingDecimal::maximumMessage())
        ->and($rows['31101']['credit'])->toBe('99999999999999999999.00')
        ->and($rows['31102']['errors'])->toContain('Amounts must be plain decimal numbers; scientific notation such as 1e3 is not accepted.');

    // A rejected oversized amount is not written to the preview row (it may not even fit its
    // DECIMAL(20,2) column on MySQL); the exact text stays in the verified source shown above.
    $stored = OpeningBalanceImport::query()->latest('id')->firstOrFail()->rows->keyBy('account_code');
    expect(BigDecimal::of($stored['31101']->credit)->isZero())->toBeTrue()
        ->and(BigDecimal::of($stored['11103']->debit)->isZero())->toBeTrue()
        ->and(BigDecimal::of($stored['11102']->debit)->isEqualTo('999999999999999.99') || DB::connection()->getDriverName() === 'sqlite')->toBeTrue();

    expect(fn () => previewRows($this, rawCsvUpload("11102,600000000000000.00,0,\n11103,600000000000000.00,0,\n31101,0,600000000000000.00,\n31102,0,600000000000000.00,\n")))
        ->toThrow(ValidationException::class, 'The total debit and total credit of the file cannot exceed');

    // Numeric spreadsheet cells: a large valid amount, the ceiling exceeded, and a formatted oversized cell.
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['Account Code', 'Debit', 'Credit', 'Source Reference']]);
    foreach ([['11102', 99999999999.99, null], ['11103', 1.0E15, null], ['31101', 2.0E15, '#,##0.00']] as $index => [$code, $amount, $format]) {
        $row = $index + 2;
        $sheet->setCellValueExplicit("A{$row}", $code, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("B{$row}", $amount, DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit("C{$row}", 0, DataType::TYPE_NUMERIC);
        if ($format) {
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode($format);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'limit-').'.xlsx';
    (new Xlsx($book))->save($path);
    $xlsx = previewRows($this, new UploadedFile($path, 'limit.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    expect($xlsx['11102']['errors'])->toBe([])->and($xlsx['11102']['debit'])->toBe('99999999999.99')
        ->and($xlsx['11103']['errors'])->toContain(AccountingDecimal::maximumMessage())->and($xlsx['11103']['debit'])->not->toBe('999999999999999.99')
        ->and($xlsx['31101']['errors'])->toContain(AccountingDecimal::maximumMessage());
});

test('an opening balance altered beyond the limit after approval cannot be approved or posted', function () {
    $this->actingAs($this->maker);
    $batch = app(OpeningBalanceService::class)->saveDraft(limitOpening($this, [[$this->assetA->id, '100.00', '0'], [$this->equityA->id, '0', '100.00']]), $this->maker);
    $big = '5000000000000000.00';
    DB::table('opening_balance_lines')->where('opening_balance_batch_id', $batch->id)->where('account_id', $this->assetA->id)->update(['debit' => $big]);
    DB::table('opening_balance_lines')->where('opening_balance_batch_id', $batch->id)->where('account_id', $this->equityA->id)->update(['credit' => $big]);
    DB::table('opening_balance_batches')->where('id', $batch->id)->update(['total_debit' => $big, 'total_credit' => $big]);

    $service = app(OpeningBalancePostingService::class);
    expect(fn () => $service->approve($batch->fresh(), $this->approver))->toThrow(ValidationException::class, 'cannot exceed');
    // Even with a recorded, otherwise valid approval, posting re-checks the amounts first.
    DB::table('opening_balance_batches')->where('id', $batch->id)->update(['status' => 'approved', 'approved_by' => $this->approver->id, 'approved_at' => now()]);
    app(AuditLogger::class)->log('opening_balance.approved', $batch, [], $this->approver);
    expect(fn () => $service->post($batch->fresh(), $this->poster))->toThrow(ValidationException::class, 'cannot exceed')
        ->and($batch->fresh()->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0)->and(OpeningBalanceImport::query()->count())->toBe(0);
});
