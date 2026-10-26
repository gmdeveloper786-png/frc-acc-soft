<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\BankReconciliation\ReconciliationCalculator;
use App\Services\BankReconciliation\ReconciliationService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// One organisational bank ledger backs a bank account in each of two branches.

beforeEach(function () {
    Storage::fake('local');
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->branchA = Branch::factory()->create(['name' => 'Shahrah-e-Faisal', 'status' => 'publish']);
    $this->branchB = Branch::factory()->create(['name' => 'Malir Town', 'status' => 'publish']);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->january = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->february = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'February 2026', 'period_number' => 2, 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => AccountingPeriodStatus::Open]);
    $this->bankLedger = Account::factory()->create(['code' => '11201', 'name' => 'Bank - Meezan Current']);
    $this->contra = Account::factory()->create(['code' => '42102', 'name' => 'Therapy Session Fees', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->bankA = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Meezan - Shahrah-e-Faisal', 'bank_name' => 'Meezan Bank', 'branch_id' => $this->branchA, 'account_id' => $this->bankLedger]);
    $this->bankB = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Meezan - Malir Town', 'bank_name' => 'Meezan Bank', 'branch_id' => $this->branchB, 'account_id' => $this->bankLedger]);
    $this->preparer = bankRecUser(SystemRole::Accountant, $this->branchA);
    $this->approver = bankRecUser(SystemRole::Approver, $this->branchA);
});

function bankRecUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** Posts a system journal on the bank ledger in the account's branch: positive = deposit (debit), negative = payment (credit). */
function bankRecJournal(object $test, FinancialAccount $account, string $date, string $signedAmount, ?string $reference = null): JournalLine
{
    $deposit = ! str_starts_with($signedAmount, '-');
    $amount = ltrim($signedAmount, '-');
    $period = $date < '2026-02-01' ? $test->january : $test->february;
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', $deposit ? 'BR' : 'BP')->value('id'), 'transaction_date' => $date,
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $period->id, 'branch_id' => $account->branch_id,
        'narration' => $deposit ? 'Fee deposited' : 'Supplier cheque', 'reference_number' => $reference,
        'source_type' => 'bank_reconciliation_test', 'source_key' => 'bank-rec-test:'.Str::uuid(), 'status' => JournalStatus::Approved,
        'total_debit' => $amount, 'total_credit' => $amount, 'created_by' => $test->admin->id,
    ]);
    $journal->lines()->create(['line_number' => 1, 'account_id' => $deposit ? $account->account_id : $test->contra->id, 'debit' => $amount, 'credit' => '0']);
    $journal->lines()->create(['line_number' => 2, 'account_id' => $deposit ? $test->contra->id : $account->account_id, 'debit' => '0', 'credit' => $amount]);
    app(JournalPostingService::class)->postSystem($journal, $test->admin);

    return JournalLine::query()->where('journal_entry_id', $journal->id)->where('account_id', $account->account_id)->firstOrFail();
}

function bankRecStatement(FinancialAccount $account, array $attributes = []): BankStatement
{
    return BankStatement::factory()->create(['financial_account_id' => $account->id, 'branch_id' => $account->branch_id, 'uploaded_by' => User::factory(), ...$attributes]);
}

function bankRecLine(BankStatement $statement, string $date, string $amount, array $attributes = []): BankStatementLine
{
    return BankStatementLine::factory()->create(['bank_statement_id' => $statement->id, 'financial_account_id' => $statement->financial_account_id, 'transaction_date' => $date, 'amount' => $amount, ...$attributes]);
}

function bankRecStart(object $test, FinancialAccount $account, string $date, string $balance, ?User $user = null): BankReconciliation
{
    $test->actingAs($user ?? $test->preparer)->post(route('bank-reconciliations.store'), ['financial_account_id' => $account->id, 'statement_date' => $date, 'statement_closing_balance' => $balance])
        ->assertSessionHasNoErrors();

    return BankReconciliation::query()->where('financial_account_id', $account->id)->latest('id')->firstOrFail();
}

function bankRecFigures(BankReconciliation $reconciliation): array
{
    $figures = app(ReconciliationCalculator::class)->calculate($reconciliation->refresh());

    return collect(['statement_balance', 'book_balance', 'uncleared_total', 'unpresented_total', 'unrecorded_total', 'difference'])
        ->mapWithKeys(fn (string $key): array => [$key => (string) $figures[$key]->toScale(2)])->all();
}

function bankRecXlsx(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            $sheet->setCellValue([$c + 1, $r + 1], $value);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'bank-rec').'.xlsx';
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'meezan-january.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function bankRecCsv(string $name = 'meezan-january.csv', string $extraRow = ''): UploadedFile
{
    $csv = "Meezan Bank statement\nDate,Particulars,Cheque No,Withdrawal,Deposit,Balance\n"
        ."01/01/2026,Balance brought forward,,,,\"1,000.00\"\n"
        ."05/01/2026,Cash deposit,,,\"2,500.00\",\"3,500.00\"\n"
        ."10/01/2026,Cheque paid,000123,750.50,,\"2,749.50\"\n"
        ."31/01/2026,Bank charges,,25.00,,\"2,724.50\"\n".$extraRow;

    return UploadedFile::fake()->createWithContent($name, $csv);
}

function bankRecCsvMapping(array $overrides = []): array
{
    return ['date_column' => 'A', 'description_column' => 'B', 'reference_column' => 'C', 'amount_mode' => 'split', 'withdrawal_column' => 'D', 'deposit_column' => 'E', 'balance_column' => 'F',
        'date_format' => 'd/m/Y', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'opening_balance' => '1,000.00', 'closing_balance' => '2724.50', ...$overrides];
}

test('a CSV statement is stored privately, mapped and imported with signed amounts', function () {
    $this->actingAs($this->preparer)->post(route('bank-statements.store'), ['financial_account_id' => $this->bankA->id, 'statement_file' => bankRecCsv()])
        ->assertSessionHasNoErrors();
    $statement = BankStatement::query()->sole();
    expect($statement->status)->toBe(BankStatement::PENDING)
        ->and($statement->header_row)->toBe(2)
        ->and($statement->detected_headers)->toMatchArray(['A' => 'Date', 'D' => 'Withdrawal', 'E' => 'Deposit'])
        ->and(BankStatementLine::query()->count())->toBe(0);
    Storage::disk('local')->assertExists($statement->stored_path);

    $this->actingAs($this->preparer)->get(route('bank-statements.show', $statement))->assertOk()->assertSee('Column mapping')->assertDontSee('Cheque paid');
    $this->actingAs($this->preparer)->post(route('bank-statements.import', $statement), bankRecCsvMapping())
        ->assertRedirect(route('bank-statements.show', $statement))->assertSessionHasNoErrors();

    $statement->refresh();
    expect($statement->status)->toBe(BankStatement::IMPORTED)
        ->and($statement->opening_balance)->toBe('1000.0000')
        ->and($statement->closing_balance)->toBe('2724.5000')
        ->and($statement->line_count)->toBe(3)
        ->and($statement->lines->map(fn ($line) => [$line->transaction_date->toDateString(), $line->reference, $line->amount, $line->status])->all())->toBe([
            ['2026-01-05', null, '2500.0000', 'unmatched'],
            ['2026-01-10', '000123', '-750.5000', 'unmatched'],
            ['2026-01-31', null, '-25.0000', 'unmatched'],
        ]);
    $audit = AuditLog::query()->where('event', 'bank_statement.imported')->sole();
    expect($audit->properties)->toMatchArray(['lines' => 3, 'total_deposits' => '2500.0000', 'total_withdrawals' => '775.5000'])
        ->and(json_encode($audit->properties))->not->toContain('Cheque paid');
});

test('an XLSX statement with a signed amount column and Excel dates is imported', function () {
    $file = bankRecXlsx([
        ['Txn Date', 'Narration', 'Ref', 'Amount', 'Running Balance'],
        [ExcelDate::formattedPHPToExcel(2026, 1, 3), 'Fee deposit', 'DEP-1', 1200.5, 1200.5],
        [ExcelDate::formattedPHPToExcel(2026, 1, 9), 'Rent cheque', 'CHQ-77', -400, 800.5],
    ]);
    $this->actingAs($this->preparer)->post(route('bank-statements.store'), ['financial_account_id' => $this->bankA->id, 'statement_file' => $file])->assertSessionHasNoErrors();
    $statement = BankStatement::query()->sole();

    $this->actingAs($this->preparer)->post(route('bank-statements.import', $statement), [
        'date_column' => 'A', 'description_column' => 'B', 'reference_column' => 'C', 'amount_mode' => 'signed', 'amount_column' => 'D', 'balance_column' => 'E',
        'date_format' => 'Y-m-d', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'opening_balance' => '0', 'closing_balance' => '800.50',
    ])->assertSessionHasNoErrors();

    expect(BankStatementLine::query()->orderBy('id')->get()->map(fn ($line) => [$line->transaction_date->toDateString(), $line->reference, $line->amount, $line->running_balance])->all())->toBe([
        ['2026-01-03', 'DEP-1', '1200.5000', '1200.5000'],
        ['2026-01-09', 'CHQ-77', '-400.0000', '800.5000'],
    ]);
});

test('the same statement file cannot be uploaded twice for one bank account', function () {
    $this->actingAs($this->preparer)->post(route('bank-statements.store'), ['financial_account_id' => $this->bankA->id, 'statement_file' => bankRecCsv()])->assertSessionHasNoErrors();

    $this->actingAs($this->preparer)->post(route('bank-statements.store'), ['financial_account_id' => $this->bankA->id, 'statement_file' => bankRecCsv('renamed-copy.csv')])
        ->assertSessionHasErrors(['statement_file' => 'This statement file has already been uploaded for this bank account.']);

    expect(BankStatement::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'bank_statement.duplicate_refused')->count())->toBe(1);
});

test('an invalid statement imports nothing and does not echo file contents', function () {
    $this->actingAs($this->preparer)->post(route('bank-statements.store'), ['financial_account_id' => $this->bankA->id, 'statement_file' => bankRecCsv(extraRow: "31/01/2026,Profit credit,,,0.125,\n")]);
    $statement = BankStatement::query()->sole();

    $response = $this->actingAs($this->preparer)->post(route('bank-statements.import', $statement), bankRecCsvMapping());
    $response->assertSessionHasErrors('mapping');
    expect(session('errors')->get('mapping'))->toContain('Row 7: the deposit or withdrawal is not a number with at most two decimals.')
        ->and(implode(' ', session('errors')->get('mapping')))->not->toContain('Profit credit')
        ->and(BankStatementLine::query()->count())->toBe(0)
        ->and($statement->refresh()->status)->toBe(BankStatement::PENDING);

    $this->actingAs($this->preparer)->post(route('bank-statements.import', $statement), bankRecCsvMapping(['closing_balance' => '2724.49']))->assertSessionHasErrors('mapping');
    expect(BankStatementLine::query()->count())->toBe(0);
});

test('auto-match suggests equal amounts within three days and applies them', function () {
    $deposit = bankRecJournal($this, $this->bankA, '2026-01-05', '2500.00', 'DEP-55');
    $cheque = bankRecJournal($this, $this->bankA, '2026-01-10', '-750.50', '000123');
    bankRecJournal($this, $this->bankA, '2026-01-20', '300.00');
    $statement = bankRecStatement($this->bankA);
    $depositLine = bankRecLine($statement, '2026-01-07', '2500.00');
    $chequeLine = bankRecLine($statement, '2026-01-12', '-750.50', ['reference' => '000123']);
    $lateLine = bankRecLine($statement, '2026-01-25', '300.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '2050.00');

    $suggestions = app(ReconciliationCalculator::class)->suggestions($reconciliation);
    expect(collect($suggestions)->map(fn ($s) => [$s['statement_line']->id, $s['journal_line']->id])->all())->toBe([[$chequeLine->id, $cheque->id], [$depositLine->id, $deposit->id]])
        ->and(collect(app(ReconciliationCalculator::class)->suggestions($reconciliation, true))->pluck('journal_line.id')->all())->toBe([$cheque->id]);

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.auto-match', $reconciliation))->assertSessionHasNoErrors();

    expect(BankReconciliationMatch::query()->pluck('journal_line_id')->sort()->values()->all())->toBe([$deposit->id, $cheque->id])
        ->and(BankReconciliationMatch::query()->pluck('match_type')->unique()->all())->toBe(['auto'])
        ->and($lateLine->refresh()->status)->toBe('unmatched')
        ->and($depositLine->refresh()->status)->toBe('matched');
    expect(AuditLog::query()->where('event', 'bank_reconciliation.auto_matched')->sole()->properties)->toMatchArray(['matches' => 2]);
});

test('a statement line is matched manually and only to an equal book amount', function () {
    $deposit = bankRecJournal($this, $this->bankA, '2026-01-05', '2500.00');
    $statement = bankRecStatement($this->bankA);
    $wrong = bankRecLine($statement, '2026-01-20', '2400.00');
    $right = bankRecLine($statement, '2026-01-20', '2500.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '4900.00');

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $wrong->id, 'journal_line_id' => $deposit->id])
        ->assertSessionHasErrors('journal_line_id');
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $right->id, 'journal_line_id' => $deposit->id])
        ->assertSessionHasNoErrors();

    $match = BankReconciliationMatch::query()->sole();
    expect($match->match_type)->toBe('manual')->and($match->bank_statement_line_id)->toBe($right->id)->and($right->refresh()->status)->toBe('matched');

    $this->actingAs($this->preparer)->delete(route('bank-reconciliations.matches.destroy', [$reconciliation, $match]))->assertSessionHasNoErrors();
    expect(BankReconciliationMatch::query()->count())->toBe(0)->and($right->refresh()->status)->toBe('unmatched');
});

test('the difference accounts for uncleared deposits, unpresented payments and unrecorded bank items', function () {
    $cleared = bankRecJournal($this, $this->bankA, '2026-01-03', '5000.00');
    $clearedCheque = bankRecJournal($this, $this->bankA, '2026-01-08', '-1200.00');
    bankRecJournal($this, $this->bankA, '2026-01-30', '800.00');      // uncleared deposit
    bankRecJournal($this, $this->bankA, '2026-01-29', '-450.25');     // unpresented cheque
    bankRecJournal($this, $this->bankA, '2026-02-03', '999.00');      // after the statement date
    $statement = bankRecStatement($this->bankA);
    $line1 = bankRecLine($statement, '2026-01-04', '5000.00');
    $line2 = bankRecLine($statement, '2026-01-10', '-1200.00');
    $charges = bankRecLine($statement, '2026-01-31', '-35.00', ['description' => 'Bank charges']);
    // Bank: 5000 − 1200 − 35 = 3765. Book: 5000 − 1200 + 800 − 450.25 = 4149.75.
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '3765.00');
    foreach ([[$line1, $cleared], [$line2, $clearedCheque]] as [$line, $book]) {
        $this->actingAs($this->preparer)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $line->id, 'journal_line_id' => $book->id])->assertSessionHasNoErrors();
    }

    expect(bankRecFigures($reconciliation))->toBe([
        'statement_balance' => '3765.00', 'book_balance' => '4149.75', 'uncleared_total' => '800.00', 'unpresented_total' => '450.25',
        'unrecorded_total' => '-35.00', 'difference' => '-35.00',
    ]);
    $this->actingAs($this->preparer)->get(route('bank-reconciliations.show', $reconciliation))->assertOk()
        ->assertSee('Balance as per bank statement + uncleared deposits − unpresented payments = balance as per bank book')
        ->assertSee('Bank charges')->assertSee('-35.00');

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.submit', $reconciliation))
        ->assertSessionHasErrors(['status' => 'The reconciliation difference is -35.00. It must be exactly 0.00 before submission or approval.']);
    expect($reconciliation->refresh()->status)->toBe(BankReconciliation::DRAFT)
        ->and(JournalEntry::query()->count())->toBe(5);

    // The accountant records the charge through the normal payment workflow, then matches it.
    $chargeEntry = bankRecJournal($this, $this->bankA, '2026-01-31', '-35.00');
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $charges->id, 'journal_line_id' => $chargeEntry->id])->assertSessionHasNoErrors();

    expect(bankRecFigures($reconciliation)['difference'])->toBe('0.00');
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.submit', $reconciliation))->assertSessionHasNoErrors();
    expect($reconciliation->refresh()->status)->toBe(BankReconciliation::SUBMITTED);
});

test('a maker cannot approve and an independent approver locks the reconciliation', function () {
    $deposit = bankRecJournal($this, $this->bankA, '2026-01-05', '1000.00');
    $line = bankRecLine(bankRecStatement($this->bankA), '2026-01-05', '1000.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '1000.00', $this->admin);
    $this->actingAs($this->approver)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $line->id, 'journal_line_id' => $deposit->id])->assertForbidden();
    $this->actingAs($this->admin)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $line->id, 'journal_line_id' => $deposit->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('bank-reconciliations.submit', $reconciliation))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('bank-reconciliations.approve', $reconciliation))->assertSessionHasErrors('approval');
    expect($reconciliation->refresh()->status)->toBe(BankReconciliation::SUBMITTED);

    // Someone who changed the matches is a maker too, whoever started the reconciliation.
    $matcher = bankRecUser(SystemRole::Accountant, $this->branchA);
    $matcher->givePermissionTo('bank_reconciliation.approve');
    $this->actingAs($this->approver)->post(route('bank-reconciliations.reject', $reconciliation), ['reason' => 'Check the matches again'])->assertSessionHasNoErrors();
    $this->actingAs($matcher)->delete(route('bank-reconciliations.matches.destroy', [$reconciliation, BankReconciliationMatch::query()->sole()]))->assertSessionHasNoErrors();
    $this->actingAs($matcher)->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $line->id, 'journal_line_id' => $deposit->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('bank-reconciliations.submit', $reconciliation))->assertSessionHasNoErrors();
    $this->actingAs($matcher)->post(route('bank-reconciliations.approve', $reconciliation))->assertSessionHasErrors('approval');

    $this->actingAs($this->approver)->post(route('bank-reconciliations.approve', $reconciliation))->assertSessionHasNoErrors();
    $reconciliation->refresh();
    expect($reconciliation->status)->toBe(BankReconciliation::APPROVED)
        ->and($reconciliation->approved_by)->toBe($this->approver->id)
        ->and($reconciliation->open_key)->toBeNull()
        ->and($reconciliation->summary['difference'])->toBe('0.0000')
        ->and(AuditLog::query()->where('auditable_type', $reconciliation->getMorphClass())->where('auditable_id', $reconciliation->id)->pluck('event')->all())
        ->toContain('bank_reconciliation.created', 'bank_reconciliation.matched', 'bank_reconciliation.unmatched', 'bank_reconciliation.submitted', 'bank_reconciliation.rejected', 'bank_reconciliation.approved', 'bank_reconciliation.approval_denied');

    // Locked: the matched journal line cannot be unmatched and nothing can be matched.
    $this->actingAs($this->admin)->delete(route('bank-reconciliations.matches.destroy', [$reconciliation, BankReconciliationMatch::query()->sole()]))
        ->assertSessionHasErrors(['status' => 'This reconciliation is approved and locked. Matches can no longer change.']);
    $this->actingAs($this->admin)->post(route('bank-reconciliations.auto-match', $reconciliation))->assertSessionHasErrors('status');
    expect(BankReconciliationMatch::query()->count())->toBe(1)->and($line->refresh()->status)->toBe('matched');

    // A later back-dated posting does not change the approved figures.
    bankRecJournal($this, $this->bankA, '2026-01-20', '50.00');
    expect(bankRecFigures($reconciliation)['book_balance'])->toBe('1000.00');
});

test('a reconciliation cannot be dated on or before the latest approved one', function () {
    BankReconciliation::factory()->approved()->create(['financial_account_id' => $this->bankA->id, 'branch_id' => $this->branchA->id, 'statement_date' => '2026-01-31', 'prepared_by' => $this->admin->id, 'summary' => []]);

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->bankA->id, 'statement_date' => '2026-01-15', 'statement_closing_balance' => '10.00'])
        ->assertSessionHasErrors(['statement_date' => 'The statement date must be after the latest approved reconciliation of this account (31 Jan 2026).']);
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->bankA->id, 'statement_date' => '2026-01-31', 'statement_closing_balance' => '10.00'])
        ->assertSessionHasErrors('statement_date');
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->bankA->id, 'statement_date' => '2026-02-28', 'statement_closing_balance' => '10.00'])
        ->assertSessionHasNoErrors();
    // One reconciliation in progress per account.
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->bankA->id, 'statement_date' => '2026-03-31', 'statement_closing_balance' => '10.00'])
        ->assertSessionHasErrors('financial_account_id');
});

test('a journal line can be matched only once across reconciliations', function () {
    $deposit = bankRecJournal($this, $this->bankA, '2026-01-05', '1000.00');
    $statement = bankRecStatement($this->bankA);
    $first = bankRecLine($statement, '2026-01-05', '1000.00');
    $duplicate = bankRecLine($statement, '2026-01-06', '1000.00');
    $january = BankReconciliation::factory()->approved()->create(['financial_account_id' => $this->bankA->id, 'branch_id' => $this->branchA->id, 'statement_date' => '2026-01-31', 'prepared_by' => $this->admin->id, 'summary' => []]);
    BankReconciliationMatch::query()->create(['bank_reconciliation_id' => $january->id, 'bank_statement_line_id' => $first->id, 'journal_line_id' => $deposit->id, 'match_type' => 'manual', 'matched_by' => $this->admin->id]);
    $first->update(['status' => 'matched']);
    $february = bankRecStart($this, $this->bankA, '2026-02-28', '2000.00');

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.matches.store', $february), ['statement_line_id' => $duplicate->id, 'journal_line_id' => $deposit->id])
        ->assertSessionHasErrors(['journal_line_id' => 'This book entry has already been matched in a reconciliation of this account.']);
    $this->actingAs($this->preparer)->delete(route('bank-reconciliations.matches.destroy', [$february, BankReconciliationMatch::query()->sole()]))->assertNotFound();

    expect(BankReconciliationMatch::query()->count())->toBe(1)
        ->and(collect(app(ReconciliationCalculator::class)->suggestions($february))->all())->toBe([]);
});

test('users only see and change bank reconciliations of their own branches', function () {
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '0.00');
    $statement = bankRecStatement($this->bankA);
    $line = bankRecLine($statement, '2026-01-05', '10.00');
    $outsider = bankRecUser(SystemRole::Accountant, $this->branchB);

    $this->actingAs($outsider)->get(route('bank-reconciliations.index'))->assertOk()->assertDontSee('Meezan - Shahrah-e-Faisal')->assertSee('Meezan - Malir Town');
    $this->actingAs($outsider)->get(route('bank-reconciliations.show', $reconciliation))->assertForbidden();
    $this->actingAs($outsider)->get(route('bank-reconciliations.statement', $reconciliation))->assertForbidden();
    $this->actingAs($outsider)->get(route('bank-reconciliations.export', [$reconciliation, 'csv']))->assertForbidden();
    $this->actingAs($outsider)->post(route('bank-reconciliations.lines.ignore', [$reconciliation, $line]))->assertForbidden();
    $this->actingAs($outsider)->post(route('bank-reconciliations.submit', $reconciliation))->assertForbidden();
    $this->actingAs($outsider)->get(route('bank-statements.show', $statement))->assertForbidden();
    $this->actingAs($outsider)->delete(route('bank-statements.destroy', $statement))->assertForbidden();
    $this->actingAs($outsider)->post(route('bank-statements.store'), ['financial_account_id' => $this->bankA->id, 'statement_file' => bankRecCsv()])->assertForbidden();
    $this->actingAs($outsider)->post(route('bank-reconciliations.store'), ['financial_account_id' => $this->bankA->id, 'statement_date' => '2026-02-28', 'statement_closing_balance' => '0'])->assertForbidden();
    expect(BankStatement::query()->count())->toBe(1)->and($line->refresh()->status)->toBe('unmatched');
});

test('a shared ledger is reconciled separately for each branch bank account', function () {
    $depositA = bankRecJournal($this, $this->bankA, '2026-01-05', '1000.00');
    bankRecJournal($this, $this->bankB, '2026-01-06', '1000.00');
    bankRecJournal($this, $this->bankB, '2026-01-07', '-300.00');
    $lineA = bankRecLine(bankRecStatement($this->bankA), '2026-01-05', '1000.00');
    $reconciliationA = bankRecStart($this, $this->bankA, '2026-01-31', '1000.00');

    $suggestions = app(ReconciliationCalculator::class)->suggestions($reconciliationA);
    expect(collect($suggestions)->pluck('journal_line.id')->all())->toBe([$depositA->id])
        ->and(app(ReconciliationCalculator::class)->outstandingBookLines($reconciliationA)->pluck('id')->all())->toBe([$depositA->id]);

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.auto-match', $reconciliationA))->assertSessionHasNoErrors();
    expect(bankRecFigures($reconciliationA))->toMatchArray(['book_balance' => '1000.00', 'uncleared_total' => '0.00', 'unpresented_total' => '0.00', 'difference' => '0.00'])
        ->and($lineA->refresh()->status)->toBe('matched');

    $reconciliationB = bankRecStart($this, $this->bankB, '2026-01-31', '0.00', $this->admin);
    expect(bankRecFigures($reconciliationB))->toMatchArray(['book_balance' => '700.00', 'uncleared_total' => '1000.00', 'unpresented_total' => '300.00', 'difference' => '0.00']);
});

test('the reconciliation statement prints and exports with the finance export permission', function () {
    bankRecJournal($this, $this->bankA, '2026-01-30', '800.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '0.00');

    $this->actingAs($this->preparer)->get(route('bank-reconciliations.statement', $reconciliation))->assertOk()
        ->assertSee('Bank Reconciliation Statement')->assertSee('Add: uncleared deposits')->assertSee('800.00');
    foreach (['csv', 'xlsx', 'pdf'] as $format) {
        $this->actingAs($this->preparer)->get(route('bank-reconciliations.export', [$reconciliation, $format]))->assertOk();
    }
    $csv = $this->actingAs($this->preparer)->get(route('bank-reconciliations.export', [$reconciliation, 'csv']))->streamedContent();
    expect($csv)->toContain('Bank Reconciliation Statement')->toContain('Total uncleared deposits')->toContain('800.0000')->toContain('Meezan - Shahrah-e-Faisal');

    $this->actingAs($this->approver)->get(route('bank-reconciliations.export', [$reconciliation, 'csv']))->assertForbidden();
});

test('every bank reconciliation route requires its permission', function () {
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '0.00');
    $statement = bankRecStatement($this->bankA);
    $line = bankRecLine($statement, '2026-01-05', '10.00');
    $match = BankReconciliationMatch::query()->create(['bank_reconciliation_id' => $reconciliation->id, 'bank_statement_line_id' => bankRecLine($statement, '2026-01-06', '20.00')->id, 'journal_line_id' => bankRecJournal($this, $this->bankA, '2026-01-06', '20.00')->id, 'match_type' => 'manual', 'matched_by' => $this->admin->id]);
    $noAccess = bankRecUser(SystemRole::Cashier, $this->branchA);
    $viewer = bankRecUser(SystemRole::Auditor, $this->branchA);

    $viewRoutes = [
        ['get', route('bank-reconciliations.index')], ['get', route('bank-statements.index')], ['get', route('bank-statements.show', $statement)],
        ['get', route('bank-reconciliations.show', $reconciliation)], ['get', route('bank-reconciliations.statement', $reconciliation)],
    ];
    $prepareRoutes = [
        ['get', route('bank-reconciliations.create')], ['post', route('bank-reconciliations.store')], ['post', route('bank-statements.store')],
        ['post', route('bank-statements.import', $statement)], ['delete', route('bank-statements.destroy', $statement)],
        ['post', route('bank-reconciliations.matches.store', $reconciliation)], ['post', route('bank-reconciliations.auto-match', $reconciliation)],
        ['post', route('bank-reconciliations.brought-forward', $reconciliation)], ['delete', route('bank-reconciliations.matches.destroy', [$reconciliation, $match])],
        ['post', route('bank-reconciliations.lines.ignore', [$reconciliation, $line])], ['post', route('bank-reconciliations.lines.restore', [$reconciliation, $line])],
        ['post', route('bank-reconciliations.submit', $reconciliation)],
    ];
    $approveRoutes = [['post', route('bank-reconciliations.approve', $reconciliation)], ['post', route('bank-reconciliations.reject', $reconciliation)]];

    foreach ([...$viewRoutes, ...$prepareRoutes, ...$approveRoutes, ['get', route('bank-reconciliations.export', [$reconciliation, 'csv'])]] as [$method, $url]) {
        $this->actingAs($noAccess)->{$method}($url)->assertForbidden();
    }
    foreach ($viewRoutes as [$method, $url]) {
        $this->actingAs($viewer)->{$method}($url)->assertOk();
    }
    foreach ([...$prepareRoutes, ...$approveRoutes] as [$method, $url]) {
        $this->actingAs($viewer)->{$method}($url)->assertForbidden();
    }
    foreach ($prepareRoutes as [$method, $url]) {
        $this->actingAs($this->approver)->{$method}($url)->assertForbidden();
    }
    $this->actingAs($this->preparer)->get(route('bank-reconciliations.create'))->assertOk()->assertSee('Meezan - Shahrah-e-Faisal')->assertDontSee('Meezan - Malir Town');
    $this->actingAs($this->preparer)->get(route('bank-statements.index'))->assertOk()->assertSee('Upload a statement');
    $this->get(route('bank-reconciliations.index'))->assertOk();
    auth()->logout();
    $this->get(route('bank-reconciliations.index'))->assertRedirect(route('login'));

    $this->actingAs($noAccess)->get(route('dashboard'))->assertOk()->assertDontSee(route('bank-reconciliations.index'));
    $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->assertSee(route('bank-reconciliations.index'));
});

test('opening entries clear as brought forward and a cancelled cheque with its reversal is not outstanding', function () {
    $opening = bankRecJournal($this, $this->bankA, '2026-01-01', '5000.00');
    $cancelled = bankRecJournal($this, $this->bankA, '2026-01-10', '-900.00');
    app(JournalReversalService::class)->reverse($cancelled->journalEntry, $this->january, '2026-01-12', 'Cheque cancelled', $this->admin);
    $statement = bankRecStatement($this->bankA, ['period_from' => '2026-01-02', 'opening_balance' => '5000.0000', 'closing_balance' => '5000.0000']);
    $line = bankRecLine($statement, '2026-01-20', '-15.00', ['description' => 'Duplicate debit reversed by bank']);
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '5000.00');

    expect(bankRecFigures($reconciliation))->toMatchArray(['book_balance' => '5000.00', 'uncleared_total' => '5000.00', 'unpresented_total' => '0.00', 'unrecorded_total' => '-15.00'])
        ->and(app(ReconciliationCalculator::class)->calculate($reconciliation)['reversal_pairs'])->toHaveCount(2);

    $this->actingAs($this->preparer)->post(route('bank-reconciliations.brought-forward', $reconciliation), ['journal_line_id' => $cancelled->id, 'note' => 'Not before the first statement'])
        ->assertSessionHasErrors('journal_line_id');
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.brought-forward', $reconciliation), ['journal_line_id' => $opening->id, 'note' => 'Opening balance agreed to the December statement'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.lines.ignore', [$reconciliation, $line]))->assertSessionHasNoErrors();

    expect(bankRecFigures($reconciliation))->toMatchArray(['uncleared_total' => '0.00', 'unrecorded_total' => '0.00', 'difference' => '0.00'])
        ->and($line->refresh()->status)->toBe('ignored')
        ->and(BankReconciliationMatch::query()->sole()->match_type)->toBe('brought_forward');
});

test('a draft can be cancelled with a reason, releasing its matches, clearances and ignored lines for a new reconciliation', function () {
    $opening = bankRecJournal($this, $this->bankA, '2026-01-01', '5000.00');
    $deposit = bankRecJournal($this, $this->bankA, '2026-01-15', '100.00');
    $statement = bankRecStatement($this->bankA, ['period_from' => '2026-01-02', 'opening_balance' => '5000.0000', 'closing_balance' => '5100.0000']);
    $line = bankRecLine($statement, '2026-01-15', '100.00');
    $noise = bankRecLine($statement, '2026-01-20', '-15.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '5100.00');
    $this->actingAs($this->preparer);
    $this->post(route('bank-reconciliations.matches.store', $reconciliation), ['statement_line_id' => $line->id, 'journal_line_id' => $deposit->id])->assertSessionHasNoErrors();
    $this->post(route('bank-reconciliations.brought-forward', $reconciliation), ['journal_line_id' => $opening->id, 'note' => 'Opening balance'])->assertSessionHasNoErrors();
    $this->post(route('bank-reconciliations.lines.ignore', [$reconciliation, $noise]))->assertSessionHasNoErrors();
    $journalLines = JournalLine::query()->count();
    $this->get(route('bank-reconciliations.show', $reconciliation))->assertSee('Cancel draft');

    // Without the prepare permission or the branch, or without a reason, nothing changes.
    $this->actingAs($this->approver)->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'Approver should not cancel'])->assertForbidden();
    $this->actingAs(bankRecUser(SystemRole::Accountant, $this->branchB))->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'Other branch should not cancel'])->assertForbidden();
    $this->actingAs($this->preparer)->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'short'])->assertSessionHasErrors('cancellation_reason');
    expect($reconciliation->fresh()->isDraft())->toBeTrue()->and($reconciliation->matches()->count())->toBe(2);

    $this->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'Wrong statement date entered'])->assertSessionHasNoErrors()->assertRedirect(route('bank-reconciliations.index'));

    $fresh = $reconciliation->fresh();
    $audit = AuditLog::query()->where('event', 'bank_reconciliation.cancelled')->where('auditable_id', $reconciliation->id)->firstOrFail();
    expect($fresh->status)->toBe(BankReconciliation::CANCELLED)->and($fresh->open_key)->toBeNull()
        ->and($fresh->matches()->count())->toBe(0)
        ->and($line->fresh()->status)->toBe(BankStatementLine::UNMATCHED)
        ->and($noise->fresh()->status)->toBe(BankStatementLine::UNMATCHED)->and($noise->fresh()->ignored_in_reconciliation_id)->toBeNull()
        ->and($statement->fresh()->status)->toBe($statement->status)->and(JournalLine::query()->count())->toBe($journalLines)
        ->and($audit->actor_id)->toBe($this->preparer->id)->and($audit->properties['reason'])->toBe('Wrong statement date entered')
        ->and(collect($audit->properties['released_matches'])->pluck('journal_line_id')->sort()->values()->all())->toBe(collect([$opening->id, $deposit->id])->sort()->values()->all())
        ->and($audit->properties['restored_ignored_line_ids'])->toBe([$noise->id]);

    // The cancelled draft stays on record, cannot be cancelled again, and no longer blocks a new reconciliation.
    $this->get(route('bank-reconciliations.show', $reconciliation))->assertOk()->assertSee('Wrong statement date entered')->assertDontSee('Cancel draft')
        ->assertSee('This reconciliation was cancelled and cannot be submitted.')->assertDontSee('before the reconciliation can be submitted');
    $this->get(route('bank-reconciliations.index'))->assertOk()->assertSee('<option value="cancelled"', false);
    $this->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'Cancelling a second time'])->assertSessionHasErrors('status');
    $next = bankRecStart($this, $this->bankA, '2026-01-31', '5100.00');
    expect($next->id)->not->toBe($reconciliation->id)
        ->and(app(ReconciliationCalculator::class)->outstandingBookLines($next)->pluck('id'))->toContain($deposit->id)->toContain($opening->id);
});

test('submitted and approved reconciliations cannot be cancelled', function () {
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '0.00');
    foreach ([BankReconciliation::SUBMITTED, BankReconciliation::APPROVED] as $status) {
        $reconciliation->update(['status' => $status, 'open_key' => $status === BankReconciliation::SUBMITTED ? '1' : null]);
        $this->actingAs($this->preparer)->post(route('bank-reconciliations.cancel', $reconciliation), ['cancellation_reason' => 'Should not be allowed'])->assertSessionHasErrors('status');
        expect($reconciliation->fresh()->status)->toBe($status);
    }
});

test('a journal whose bank line is matched in a reconciliation cannot be reversed until the match is released, and never once approved', function () {
    $service = app(ReconciliationService::class);
    $reversals = app(JournalReversalService::class);
    $reverse = fn (JournalLine $line) => $reversals->reverse($line->journalEntry->fresh(), $this->january, '2026-01-25', 'Cheque cancelled', $this->admin);
    $statement = bankRecStatement($this->bankA, ['period_from' => '2026-01-01', 'opening_balance' => '0.0000', 'closing_balance' => '-1500.0000']);
    $matched = bankRecJournal($this, $this->bankA, '2026-01-10', '-900.00');
    $other = bankRecJournal($this, $this->bankA, '2026-01-11', '-600.00');
    $free = bankRecJournal($this, $this->bankA, '2026-01-12', '-50.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '-1500.00');
    $match = $service->match($reconciliation, bankRecLine($statement, '2026-01-10', '-900.00')->id, $matched->id, $this->preparer);

    // Matched in a draft reconciliation: refused, with nothing written and the match untouched.
    $journals = JournalEntry::query()->count();
    expect(fn () => $reverse($matched))->toThrow(ValidationException::class, 'Unmatch it there before reversing')
        ->and(JournalEntry::query()->count())->toBe($journals)->and($matched->journalEntry->fresh()->status->value)->toBe('posted')
        ->and(BankReconciliationMatch::query()->whereKey($match->id)->exists())->toBeTrue()
        ->and($reversals->requestBlockedReason($matched->journalEntry->fresh()))->not->toBeNull();

    // A line nobody matched reverses normally; once unmatched through the reconciliation, so does the matched one.
    expect($reverse($free)->status->value)->toBe('posted');
    $service->unmatch($reconciliation, $match, $this->preparer);
    expect($reverse($matched)->status->value)->toBe('posted');

    // Submitted and approved reconciliations block too; an approved one is locked for good.
    $service->match($reconciliation, bankRecLine($statement, '2026-01-11', '-600.00')->id, $other->id, $this->preparer);
    foreach ([BankReconciliation::SUBMITTED => 'awaiting approval', BankReconciliation::APPROVED => 'which is locked'] as $status => $message) {
        DB::table('bank_reconciliations')->where('id', $reconciliation->id)->update(['status' => $status]);
        expect(fn () => $reverse($other))->toThrow(ValidationException::class, $message);
    }
    expect($other->journalEntry->fresh()->status->value)->toBe('posted')->and(BankReconciliationMatch::query()->where('journal_line_id', $other->id)->exists())->toBeTrue();
});

test('cancelling a draft reconciliation releases its matches, so their journals can be reversed again', function () {
    $service = app(ReconciliationService::class);
    $statement = bankRecStatement($this->bankA, ['period_from' => '2026-01-01', 'opening_balance' => '0.0000', 'closing_balance' => '-900.0000']);
    $line = bankRecJournal($this, $this->bankA, '2026-01-10', '-900.00');
    $reconciliation = bankRecStart($this, $this->bankA, '2026-01-31', '-900.00');
    $service->match($reconciliation, bankRecLine($statement, '2026-01-10', '-900.00')->id, $line->id, $this->preparer);
    $service->cancel($reconciliation->fresh(), 'Started on the wrong statement', $this->preparer);

    expect(app(JournalReversalService::class)->reverse($line->journalEntry->fresh(), $this->january, '2026-01-25', 'Cheque cancelled', $this->admin)->status->value)->toBe('posted');
});
