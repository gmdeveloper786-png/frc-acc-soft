<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\GeneralLedgerService;
use App\Services\JournalPostingService;
use App\Services\JournalWorkflowService;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use App\Services\TrialBalanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->poster = User::factory()->create();
    $this->poster->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    foreach ([1 => ['2026-01-01', '2026-01-31'], 2 => ['2026-02-01', '2026-02-28'], 3 => ['2026-03-01', '2026-03-31']] as $number => [$start, $end]) {
        AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'Period '.$number, 'period_number' => $number, 'start_date' => $start, 'end_date' => $end, 'status' => AccountingPeriodStatus::Open]);
    }
    $this->branch = Branch::factory()->create(['name' => 'Export Branch']);
    $this->otherBranch = Branch::factory()->create(['name' => 'Foreign Branch']);
    $this->group = Account::factory()->create(['code' => '110000', 'name' => 'Current Assets', 'level' => 'group', 'is_postable' => false]);
    $this->cashLedger = Account::factory()->create(['code' => '111001', 'name' => 'Cash In Hand', 'parent_id' => $this->group->id]);
    $this->incomeLedger = Account::factory()->create(['code' => '411001', 'name' => 'Therapy Income', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->voucherType = VoucherType::query()->where('code', 'JV')->firstOrFail();

    // A reader of exactly this branch, with view and export permissions.
    $this->reader = User::factory()->create(['name' => 'Ledger Reader']);
    $this->reader->givePermissionTo(['ledgers.view', 'trial_balance.view', 'reports.export', 'cash_books.view', 'finance_reports.view', 'finance_reports.export']);
    $this->reader->branches()->attach($this->branch);
});

/** Creates a journal (cash debit by default); `$post = false` leaves it as a draft. */
function ledgerexportJournal(object $test, string $date, string $amount, string $narration = 'Export fixture', bool $cashCredit = false, ?Branch $branch = null, bool $post = true): JournalEntry
{
    $cash = ['account_id' => $test->cashLedger->id, 'debit' => $cashCredit ? '0' : $amount, 'credit' => $cashCredit ? $amount : '0'];
    $income = ['account_id' => $test->incomeLedger->id, 'debit' => $cashCredit ? $amount : '0', 'credit' => $cashCredit ? '0' : $amount];
    $workflow = app(JournalWorkflowService::class);
    $test->actingAs($test->maker);
    $journal = $workflow->create([
        'voucher_type_id' => $test->voucherType->id, 'transaction_date' => $date, 'fiscal_year_id' => $test->year->id,
        'accounting_period_id' => AccountingPeriod::query()->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->value('id'),
        'branch_id' => ($branch ?? $test->branch)->id, 'narration' => $narration, 'reference_number' => 'LX-REF', 'lines' => [$cash, $income],
    ], $test->maker);
    if (! $post) {
        return $journal;
    }
    $workflow->submit($journal, $test->maker);
    $test->actingAs($test->checker);
    $workflow->approve($journal->fresh(), $test->checker);

    return app(JournalPostingService::class)->post($journal->fresh(), $test->poster);
}

/** @param array<string, mixed> $parameters */
function ledgerexportDownload(object $test, string $route, array $parameters): string
{
    $response = $test->actingAs($test->reader)->get(route($route, $parameters));
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.'.$parameters['format']);

    return $response->streamedContent();
}

/**
 * The table part of an exported CSV (after the column heading row), as rows keyed by column label.
 *
 * @return list<array<string, string>>
 */
function ledgerexportCsvTable(string $csv, string $firstColumn): array
{
    $handle = fopen('php://temp', 'w+');
    fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    rewind($handle);
    $header = null;
    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if ($header === null) {
            $header = ($line[0] ?? null) === $firstColumn && count($line) > 1 ? $line : null;

            continue;
        }
        $rows[] = array_combine($header, $line);
    }

    return $rows;
}

function ledgerexportSheet(string $content): Worksheet
{
    $path = tempnam(sys_get_temp_dir(), 'lx').'.xlsx';
    file_put_contents($path, $content);
    $sheet = IOFactory::load($path)->getActiveSheet();
    unlink($path);

    return $sheet;
}

/** @return array<string, mixed> */
function ledgerexportLedgerQuery(object $test, array $extra = []): array
{
    return ['fiscal_year_id' => $test->year->id, 'account_id' => $test->cashLedger->id, 'from' => '2026-02-01', 'to' => '2026-02-28', ...$extra];
}

test('general ledger csv exports every line of a multi page ledger with exact opening running and closing balances', function () {
    ledgerexportJournal($this, '2026-01-10', '1000.1250');
    ledgerexportJournal($this, '2026-01-20', '250.3333', cashCredit: true);
    for ($i = 1; $i <= 62; $i++) {
        ledgerexportJournal($this, '2026-02-'.str_pad((string) (($i % 25) + 1), 2, '0', STR_PAD_LEFT), bcadd('100.1111', (string) $i, 4), 'Line '.$i, cashCredit: $i % 5 === 0);
    }
    ledgerexportJournal($this, '2026-02-12', '77.0000', 'DRAFT-ONLY', post: false);
    ledgerexportJournal($this, '2026-02-12', '55.0000', 'OTHER-BRANCH', branch: $this->otherBranch);

    $service = app(GeneralLedgerService::class)->report($this->cashLedger, [$this->branch->id], '2026-02-01', '2026-02-28', null, 1000);
    $expected = collect($service['rows']->items())->values();
    $csv = ledgerexportDownload($this, 'reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv']));
    $table = ledgerexportCsvTable($csv, 'Date');
    $lines = array_slice($table, 1, -2);

    expect($expected)->toHaveCount(62)
        ->and($service['opening_balance'])->toBe('749.7917')
        ->and($lines)->toHaveCount(62)
        ->and($table[0]['Date'])->toBe('Opening balance')
        ->and($table[0]['Running Balance'])->toBe($service['opening_balance'])
        ->and($table[0]['Dr/Cr'])->toBe('DR');

    $totalDebit = '0.0000';
    $totalCredit = '0.0000';
    foreach ($expected as $index => $row) {
        expect($lines[$index]['Voucher'])->toBe($row['voucher_number'])
            ->and($lines[$index]['Narration'])->toBe($row['narration'])
            ->and($lines[$index]['Debit'])->toBe($row['debit'])
            ->and($lines[$index]['Credit'])->toBe($row['credit'])
            ->and($lines[$index]['Running Balance'])->toBe($row['running_balance'])
            ->and($lines[$index]['Dr/Cr'])->toBe(strtoupper(substr($row['running_side'], 0, 1)).'R');
        $totalDebit = bcadd($totalDebit, $row['debit'], 4);
        $totalCredit = bcadd($totalCredit, $row['credit'], 4);
    }
    $totals = $table[count($table) - 2];
    $closing = $table[count($table) - 1];

    expect($totals['Date'])->toBe('Period totals')
        ->and($totals['Debit'])->toBe($totalDebit)
        ->and($totals['Credit'])->toBe($totalCredit)
        ->and($closing['Date'])->toBe('Closing balance')
        ->and($closing['Running Balance'])->toBe($service['closing_balance'])
        ->and($closing['Running Balance'])->toBe(bcsub(bcadd('749.7917', $totalDebit, 4), $totalCredit, 4))
        ->and($csv)->not->toContain('DRAFT-ONLY')->not->toContain('OTHER-BRANCH')
        ->toContain('Account: 111001 - Cash In Hand')->toContain('Branch: All authorized branches');

    // The screen shows one page of the same ledger; the export is not limited to it.
    $this->actingAs($this->reader)->get(route('reports.general-ledger', ledgerexportLedgerQuery($this)))
        ->assertOk()->assertSee('Line 1')->assertSee('export/xlsx', false)->assertSee('page=2', false);
    expect(AuditLog::query()->where('event', 'report.exported')->latest('id')->first()->properties['rows'])->toBe(65);
});

test('general ledger xlsx keeps exact numeric figures and stores a formula-like narration as text', function () {
    ledgerexportJournal($this, '2026-01-15', '500.0000');
    ledgerexportJournal($this, '2026-02-03', '123.4567', '=HYPERLINK("http://evil.test","Pay")');
    ledgerexportJournal($this, '2026-02-04', '23.4500', '+Refund', cashCredit: true);

    $service = app(GeneralLedgerService::class)->report($this->cashLedger, [$this->branch->id], '2026-02-01', '2026-02-28', null);
    $sheet = ledgerexportSheet(ledgerexportDownload($this, 'reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'xlsx', 'branch_id' => $this->branch->id])));
    $rows = collect($sheet->toArray(null, false, false, false));
    $heading = $rows->search(fn (array $row): bool => $row[0] === 'Date' && $row[1] === 'Voucher');
    $formulaRow = $rows->search(fn (array $row): bool => str_contains((string) $row[3], 'HYPERLINK'));
    $exact = fn (mixed $value): string => number_format((float) $value, 4, '.', '');

    expect($rows[$heading + 1][0])->toBe('Opening balance')
        ->and($exact($rows[$heading + 1][8]))->toBe('500.0000')->toBe($service['opening_balance'])
        ->and($sheet->getCell('D'.($formulaRow + 1))->getDataType())->toBe('s')
        ->and($sheet->getCell('D'.($formulaRow + 1))->getValue())->toBe('=HYPERLINK("http://evil.test","Pay")')
        ->and($sheet->getCell('G'.($formulaRow + 1))->getDataType())->toBe('n')
        ->and($exact($rows[$formulaRow][6]))->toBe('123.4567')
        ->and($exact($rows[$formulaRow][8]))->toBe('623.4567')->toBe($service['rows']->items()[0]['running_balance'])
        ->and($exact($rows[$formulaRow + 1][7]))->toBe('23.4500')
        ->and($exact($rows[$formulaRow + 1][8]))->toBe('600.0067')
        ->and($rows[$formulaRow + 2][0])->toBe('Period totals')
        ->and($exact($rows[$formulaRow + 2][6]))->toBe('123.4567')
        ->and($exact($rows[$formulaRow + 2][7]))->toBe('23.4500')
        ->and($rows[$formulaRow + 3][0])->toBe('Closing balance')
        ->and($exact($rows[$formulaRow + 3][8]))->toBe('600.0067')->toBe($service['closing_balance']);

    $csv = ledgerexportDownload($this, 'reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv']));
    expect($csv)->toContain("\"'=HYPERLINK(\"\"http://evil.test\"\",\"\"Pay\"\")\"")->toContain("'+Refund")
        ->and($csv)->not->toContain(',"=HYPERLINK')->not->toContain(',+Refund');
});

test('general ledger and trial balance pdf exports are real pdf documents containing the totals', function () {
    ledgerexportJournal($this, '2026-01-15', '1000.0000');
    ledgerexportJournal($this, '2026-02-03', '1234.5000');
    ledgerexportJournal($this, '2026-02-04', '34.2500', cashCredit: true);

    $ledger = ledgerexportDownload($this, 'reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'pdf']));
    $trial = ledgerexportDownload($this, 'reports.trial-balance.export', ['fiscal_year_id' => $this->year->id, 'as_of' => '2026-02-28', 'format' => 'pdf']);

    expect(str_starts_with($ledger, '%PDF-1.4'))->toBeTrue()
        ->and(str_ends_with($ledger, '%%EOF'))->toBeTrue()
        ->and($ledger)->toContain('(General Ledger)')->toContain('(Opening balance)')->toContain('(1,000.00)')
        ->toContain('(Period totals)')->toContain('(1,234.50)')->toContain('(34.25)')
        ->toContain('(Closing balance)')->toContain('(2,200.25)')
        ->and(str_starts_with($trial, '%PDF-1.4'))->toBeTrue()
        ->and(str_ends_with($trial, '%%EOF'))->toBeTrue()
        ->and($trial)->toContain('(Trial Balance)')->toContain('(Grand totals \(ledgers only\))')->toContain('(2,200.25)');
});

test('trial balance csv and xlsx equal the service figures and exclude draft journals', function () {
    ledgerexportJournal($this, '2026-01-10', '1000.1250');
    ledgerexportJournal($this, '2026-02-05', '123.4567');
    ledgerexportJournal($this, '2026-02-06', '0.0001', cashCredit: true);
    ledgerexportJournal($this, '2026-02-07', '9999.0000', 'DRAFT-ONLY', post: false);
    ledgerexportJournal($this, '2026-03-02', '500.0000', 'After the as-of date');
    ledgerexportJournal($this, '2026-02-08', '77.0000', branch: $this->otherBranch);

    $service = app(TrialBalanceService::class)->report($this->year->id, '2026-02-28', [$this->branch->id]);
    $query = ['fiscal_year_id' => $this->year->id, 'as_of' => '2026-02-28'];
    $table = collect(ledgerexportCsvTable(ledgerexportDownload($this, 'reports.trial-balance.export', [...$query, 'format' => 'csv']), 'Account Code'));

    expect($service['total_debit'])->toBe('1123.5816')->and($service['balanced'])->toBeTrue()
        ->and($table)->toHaveCount($service['rows']->count() + 1);
    foreach ($service['rows'] as $row) {
        $exported = $table->firstWhere('Account Code', $row['account']->code);
        expect($exported['Account Name'])->toBe($row['account']->name)
            ->and($exported['Debit Balance'])->toBe($row['debit'])
            ->and($exported['Credit Balance'])->toBe($row['credit']);
    }
    $totals = $table->last();

    expect($totals['Account Code'])->toBe('Grand totals (ledgers only)')
        ->and($totals['Debit Balance'])->toBe('1123.5816')->toBe($service['total_debit'])
        ->and($totals['Credit Balance'])->toBe('1123.5816')->toBe($service['total_credit'])
        ->and($table->firstWhere('Account Code', '110000')['Debit Balance'])->toBe('1123.5816')
        ->and($table->firstWhere('Account Code', '411001')['Credit Balance'])->toBe('1123.5816');

    $sheet = ledgerexportSheet(ledgerexportDownload($this, 'reports.trial-balance.export', [...$query, 'format' => 'xlsx']));
    $rows = collect($sheet->toArray(null, false, false, false));
    $cash = $rows->search(fn (array $row): bool => $row[0] === '111001');
    $grand = $rows->search(fn (array $row): bool => $row[0] === 'Grand totals (ledgers only)');

    expect($sheet->getCell('A'.($cash + 1))->getDataType())->toBe('s')
        ->and($sheet->getCell('D'.($cash + 1))->getDataType())->toBe('n')
        ->and(number_format((float) $rows[$cash][3], 4, '.', ''))->toBe('1123.5816')
        ->and(number_format((float) $rows[$grand][3], 4, '.', ''))->toBe($service['total_debit'])
        ->and(number_format((float) $rows[$grand][4], 4, '.', ''))->toBe($service['total_credit']);
});

test('ledger and trial balance exports need the export permission in addition to the view permission', function () {
    ledgerexportJournal($this, '2026-02-05', '100.0000');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['ledgers.view', 'trial_balance.view']);
    $viewer->branches()->attach($this->branch);
    $trialQuery = ['fiscal_year_id' => $this->year->id, 'as_of' => '2026-02-28'];

    $this->actingAs($viewer)->get(route('reports.general-ledger', ledgerexportLedgerQuery($this)))->assertOk()->assertDontSee('export/csv', false);
    $this->actingAs($viewer)->get(route('reports.trial-balance', $trialQuery))->assertOk()->assertDontSee('export/csv', false);
    $this->actingAs($viewer)->get(route('reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv'])))->assertForbidden();
    $this->actingAs($viewer)->get(route('reports.trial-balance.export', [...$trialQuery, 'format' => 'xlsx']))->assertForbidden();

    $exporterOnly = User::factory()->create();
    $exporterOnly->givePermissionTo('reports.export');
    $exporterOnly->branches()->attach($this->branch);
    $this->actingAs($exporterOnly)->get(route('reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv'])))->assertForbidden();
    $this->actingAs($exporterOnly)->get(route('reports.trial-balance.export', [...$trialQuery, 'format' => 'csv']))->assertForbidden();

    $this->actingAs($this->reader)->get(route('reports.trial-balance', $trialQuery))->assertOk()->assertSee('export/pdf', false);
    $this->actingAs($this->reader)->get(route('reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'docx'])))->assertNotFound();
    $this->post(route('reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv'])))->assertStatus(405);
});

test('an export for a branch outside the user scope is rejected and never widened', function () {
    ledgerexportJournal($this, '2026-02-05', '100.0000');
    ledgerexportJournal($this, '2026-02-06', '900.0000', 'OTHER-BRANCH', branch: $this->otherBranch);
    $exportsBefore = AuditLog::query()->where('event', 'report.exported')->count();

    $this->actingAs($this->reader)->get(route('reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv', 'branch_id' => $this->otherBranch->id])))->assertForbidden();
    $this->actingAs($this->reader)->get(route('reports.trial-balance.export', ['fiscal_year_id' => $this->year->id, 'as_of' => '2026-02-28', 'branch_id' => $this->otherBranch->id, 'format' => 'csv']))->assertForbidden();
    expect(AuditLog::query()->where('event', 'report.exported')->count())->toBe($exportsBefore);

    $csv = ledgerexportDownload($this, 'reports.trial-balance.export', ['fiscal_year_id' => $this->year->id, 'as_of' => '2026-02-28', 'format' => 'csv']);
    expect(collect(ledgerexportCsvTable($csv, 'Account Code'))->last()['Debit Balance'])->toBe('100.0000');
});

test('nothing is offered or exported when there is no data', function () {
    $this->actingAs($this->reader)->get(route('reports.general-ledger', ledgerexportLedgerQuery($this)))->assertOk()->assertDontSee('export/csv', false);
    $this->actingAs($this->reader)->get(route('reports.general-ledger.export', ledgerexportLedgerQuery($this, ['format' => 'csv'])))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($this->reader)->get(route('reports.general-ledger.export', ['format' => 'csv']))->assertRedirect(route('reports.general-ledger'));
    $this->actingAs($this->reader)->get(route('reports.registers', 'receipts'))->assertOk()->assertDontSee('export/csv', false);
    $this->actingAs($this->reader)->get(route('reports.registers.export', ['kind' => 'receipts', 'format' => 'csv']))->assertRedirect()->assertSessionHas('error');
    $this->actingAs($this->reader)->get(route('reports.payables.export', ['format' => 'pdf']))->assertRedirect()->assertSessionHas('error');
    expect(AuditLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('receipt register cash book and payables exports carry correct totals within the branch scope', function () {
    $payable = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $expense = Account::factory()->create(['code' => '511101', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $operations = app(OperationalTransactionService::class);
    $this->actingAs($this->maker);
    $ownCash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch->id, 'account_id' => $this->cashLedger->id]);
    $foreignCash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->otherBranch->id, 'account_id' => Account::factory()->create(['code' => '111002'])->id]);
    foreach ([[$this->branch, $ownCash, '123.4567', '=Walk-in client'], [$this->branch, $ownCash, '200.0000', 'Second client'], [$this->otherBranch, $foreignCash, '999.0000', 'FOREIGN-PARTY']] as [$branch, $cash, $amount, $party]) {
        $receipt = $operations->create(['type' => OperationalTransactionType::CashReceipt->value, 'branch_id' => $branch->id, 'transaction_date' => '2026-01-15', 'party_name' => $party, 'amount' => $amount, 'financial_account_id' => $cash->id, 'contra_account_id' => $this->incomeLedger->id, 'narration' => 'Receipt fixture'], $this->maker);
        $operations->post($receipt, $this->maker);
    }
    $vendor = Vendor::factory()->create(['name' => '@Supplier Ltd']);
    $bills = app(PurchaseBillService::class);
    foreach ([['INV-LX-1', '2026-01-05', '2.0000', '50.1250'], ['INV-LX-2', '2026-01-20', '1.0000', '300.0000']] as [$number, $due, $quantity, $unit]) {
        $this->actingAs($this->maker);
        $bill = $bills->create(['vendor_id' => $vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $payable->id, 'bill_number' => $number, 'invoice_date' => '2026-01-02', 'due_date' => $due, 'description' => 'Payable fixture', 'lines' => [['account_id' => $expense->id, 'description' => 'Supplies', 'quantity' => $quantity, 'unit_amount' => $unit]]], $this->maker);
        $bills->submit($bill, $this->maker);
        $this->actingAs($this->checker);
        $bills->approve($bill->fresh(), $this->checker);
        $bills->post($bill->fresh(), $this->checker);
    }

    $register = ledgerexportDownload($this, 'reports.registers.export', ['kind' => 'receipts', 'format' => 'csv', 'from' => '2026-01-01', 'to' => '2026-01-31']);
    $registerRows = collect(ledgerexportCsvTable($register, 'Date'));
    expect($registerRows)->toHaveCount(3)
        ->and($registerRows->pluck('Amount')->all())->toEqualCanonicalizing(['123.4567', '200.0000', '323.4567'])
        ->and($registerRows->last()['Date'])->toBe('Total')
        ->and($registerRows->last()['Amount'])->toBe('323.4567')
        ->and($register)->toContain("'=Walk-in client")->not->toContain('FOREIGN-PARTY')->toContain('From: 01 Jan 2026');

    $book = collect(ledgerexportCsvTable(ledgerexportDownload($this, 'reports.books.export', ['kind' => 'cash', 'format' => 'csv']), 'Date'));
    expect($book)->toHaveCount(3)->and($book->last()['Debit'])->toBe('323.4567')->and($book->last()['Credit'])->toBe('0.0000');

    $payables = ledgerexportDownload($this, 'reports.payables.export', ['format' => 'csv', 'as_of' => '2026-01-25']);
    $payableRows = collect(ledgerexportCsvTable($payables, 'Vendor'));
    expect($payableRows->firstWhere('Bill', 'INV-LX-1')['Outstanding'])->toBe('100.2500')
        ->and($payableRows->firstWhere('Bill', 'INV-LX-1')['Aging (days)'])->toBe('1-30')
        ->and($payableRows->firstWhere('Bill', 'INV-LX-1')['Vendor'])->toBe("'@Supplier Ltd")
        ->and($payableRows->firstWhere('Vendor', 'Total outstanding')['Outstanding'])->toBe('400.2500')
        ->and($payableRows->firstWhere('Vendor', 'Aging 1-30 days')['Outstanding'])->toBe('400.2500')
        ->and($payableRows->firstWhere('Vendor', 'Aging Current days')['Outstanding'])->toBe('0.0000');
    $this->actingAs($this->reader)->get(route('reports.payables', ['as_of' => '2026-01-25']))->assertOk()->assertSee('export/xlsx', false)->assertSee('as_of=2026-01-25', false);

    $vendorLedger = collect(ledgerexportCsvTable(ledgerexportDownload($this, 'reports.vendor-ledger.export', ['format' => 'csv', 'vendor_id' => $vendor->id]), 'Date'));
    expect($vendorLedger->last()['Bill amount'])->toBe('400.2500')->and($vendorLedger->last()['Outstanding'])->toBe('400.2500')->and($vendorLedger->last()['Paid'])->toBe('0.0000');

    $viewOnly = User::factory()->create();
    $viewOnly->givePermissionTo(['finance_reports.view', 'cash_books.view']);
    $viewOnly->branches()->attach($this->branch);
    $this->actingAs($viewOnly)->get(route('reports.registers', 'receipts'))->assertOk()->assertDontSee('export/csv', false);
    $this->actingAs($viewOnly)->get(route('reports.registers.export', ['kind' => 'receipts', 'format' => 'csv']))->assertForbidden();
    $this->actingAs($viewOnly)->get(route('reports.payables.export', ['format' => 'csv']))->assertForbidden();
    $this->actingAs($viewOnly)->get(route('reports.books.export', ['kind' => 'cash', 'format' => 'csv']))->assertForbidden();

    $noBankBook = User::factory()->create();
    $noBankBook->givePermissionTo(['cash_books.view', 'finance_reports.export']);
    $noBankBook->branches()->attach($this->branch);
    $this->actingAs($noBankBook)->get(route('reports.books.export', ['kind' => 'bank', 'format' => 'csv']))->assertForbidden();
});
