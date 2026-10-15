<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountMappingService;
use App\Services\Budgets\BudgetReportService;
use App\Services\Budgets\BudgetService;
use App\Services\DashboardService;
use App\Services\FiscalYearService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\Reports\Statements\AccountTree;
use App\Services\Reports\Statements\BalanceSheetStatement;
use App\Services\Reports\Statements\BranchIncomeExpenditureStatement;
use App\Services\Reports\Statements\IncomeExpenditureStatement;
use App\Services\Reports\Statements\ServiceAnalysisStatement;
use App\Services\TrialBalanceService;
use App\Services\YearEndClosingService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->actingAs($this->admin);

    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->january = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->december = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'December 2026', 'period_number' => 12, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);

    $this->branchA = Branch::factory()->create(['name' => 'Alpha Branch']);
    $this->branchB = Branch::factory()->create(['name' => 'Bravo Branch']);

    $this->cash = Account::factory()->create(['code' => '111001', 'name' => 'Cash']);
    $this->surplus = Account::factory()->create(['code' => '311001', 'name' => 'Accumulated Surplus', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $this->feeIncome = Account::factory()->create(['code' => '411001', 'name' => 'Fee Income', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->donationIncome = Account::factory()->create(['code' => '412001', 'name' => 'Donation Income', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->rent = Account::factory()->create(['code' => '511001', 'name' => 'Rent', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);

    // Alpha: income 1000, expense 300 -> surplus 700. Bravo: income 200, expense 500 -> deficit 300.
    yecPost($this, $this->branchA, '2026-01-10', [[$this->cash, '1000', '0'], [$this->feeIncome, '0', '1000']]);
    yecPost($this, $this->branchA, '2026-01-20', [[$this->rent, '300', '0'], [$this->cash, '0', '300']]);
    yecPost($this, $this->branchB, '2026-01-12', [[$this->cash, '200', '0'], [$this->donationIncome, '0', '200']]);
    yecPost($this, $this->branchB, '2026-12-05', [[$this->rent, '500', '0'], [$this->cash, '0', '500']]);
});

/** @param list<array{0: Account, 1: string, 2: string}> $lines */
function yecPost(object $test, Branch $branch, string $date, array $lines, ?FiscalYear $year = null, ?AccountingPeriod $period = null): JournalEntry
{
    $year ??= $test->year;
    $period ??= AccountingPeriod::query()->where('fiscal_year_id', $year->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
    $total = collect($lines)->sum(fn (array $line): int => (int) $line[1]);
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => $date,
        'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id, 'branch_id' => $branch->id,
        'narration' => 'Year-end closing fixture', 'source_system' => 'accounting', 'source_type' => 'test_fixture',
        'source_id' => (string) fake()->unique()->numberBetween(1, 999999), 'source_key' => 'test_fixture:'.fake()->unique()->uuid(),
        'status' => JournalStatus::Approved, 'total_debit' => $total, 'total_credit' => $total,
        'created_by' => $test->admin->id, 'submitted_by' => $test->admin->id, 'approved_by' => $test->admin->id,
    ]);
    foreach ($lines as $index => [$account, $debit, $credit]) {
        $journal->lines()->create(['line_number' => $index + 1, 'account_id' => $account->id, 'debit' => $debit, 'credit' => $credit]);
    }

    return app(JournalPostingService::class)->postSystem($journal, $test->admin);
}

function yecMapSurplus(object $test): void
{
    app(AccountMappingService::class)->set(AccountMappingKey::AccumulatedSurplus, $test->surplus->id, $test->admin);
}

/** @return array<string, array{debit: string, credit: string}> Lines keyed by account code. */
function yecLines(JournalEntry $journal): array
{
    return $journal->lines()->with('account')->get()
        ->mapWithKeys(fn ($line): array => [$line->account->code => ['debit' => $line->debit, 'credit' => $line->credit]])->all();
}

function yecTrialRow(array $report, Account $account): array
{
    $row = $report['rows']->first(fn (array $row): bool => $row['account']->id === $account->id);

    return ['debit' => $row['debit'], 'credit' => $row['credit']];
}

test('accumulated surplus mapping accepts only an active postable equity ledger and is optional', function () {
    expect(AccountMappingKey::AccumulatedSurplus->accountType()->value)->toBe('equity')
        ->and(AccountMappingKey::AccumulatedSurplus->isRequired())->toBeFalse();

    yecMapSurplus($this);
    expect(app(AccountMappingService::class)->resolve(AccountMappingKey::AccumulatedSurplus)->id)->toBe($this->surplus->id);
    expect(fn () => app(AccountMappingService::class)->set(AccountMappingKey::AccumulatedSurplus, $this->feeIncome->id, $this->admin))->toThrow(ValidationException::class);

    $this->get(route('account-mappings.index'))->assertOk()->assertSee('Accumulated Surplus (year-end closing)');
});

test('preview lists each branch income and expense ledgers with its net surplus', function () {
    yecMapSurplus($this);
    $preview = app(YearEndClosingService::class)->preview($this->year, $this->admin);

    expect($preview['blockers'])->toBe([])
        ->and($preview['branches'])->toHaveCount(2)
        ->and($preview['branches'][0]['branch']->id)->toBe($this->branchA->id)
        ->and($preview['branches'][0]['total_income'])->toBe('1000.0000')
        ->and($preview['branches'][0]['total_expenses'])->toBe('300.0000')
        ->and($preview['branches'][0]['surplus'])->toBe('700.0000')
        ->and(collect($preview['branches'][0]['lines'])->map(fn (array $line): array => [$line['account']->code, $line['net']])->all())->toBe([['411001', '-1000.0000'], ['511001', '300.0000']])
        ->and($preview['branches'][1]['surplus'])->toBe('-300.0000')
        ->and($preview['surplus'])->toBe('400.0000')
        ->and(JournalEntry::query()->where('source_type', 'year_end_closing')->exists())->toBeFalse();
});

test('posting creates one balanced JV per branch moving surplus and deficit to accumulated surplus', function () {
    yecMapSurplus($this);
    $journals = app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');

    expect($journals)->toHaveCount(2);
    [$alpha, $bravo] = $journals;
    expect($alpha->status)->toBe(JournalStatus::Posted)
        ->and($alpha->branch_id)->toBe($this->branchA->id)
        ->and($alpha->voucherType->code)->toBe('JV')
        ->and($alpha->transaction_date->toDateString())->toBe('2026-12-31')
        ->and($alpha->accounting_period_id)->toBe($this->december->id)
        ->and($alpha->narration)->toBe('Year-end closing FY 2026')
        ->and($alpha->source_system)->toBe('accounting')
        ->and($alpha->source_type)->toBe('year_end_closing')
        ->and($alpha->source_id)->toBe((string) $this->year->id)
        ->and($alpha->source_key)->toBe("year_end_closing:{$this->year->id}:branch:{$this->branchA->id}")
        ->and($alpha->total_debit)->toBe('1000.0000')
        ->and($alpha->total_credit)->toBe('1000.0000')
        ->and(yecLines($alpha))->toBe([
            '411001' => ['debit' => '1000.0000', 'credit' => '0.0000'],
            '511001' => ['debit' => '0.0000', 'credit' => '300.0000'],
            '311001' => ['debit' => '0.0000', 'credit' => '700.0000'],
        ])
        ->and(yecLines($bravo))->toBe([
            '412001' => ['debit' => '200.0000', 'credit' => '0.0000'],
            '511001' => ['debit' => '0.0000', 'credit' => '500.0000'],
            '311001' => ['debit' => '300.0000', 'credit' => '0.0000'],
        ])
        ->and(app(YearEndClosingService::class)->hasUnclosedBalance($this->year))->toBeFalse();

    $audit = AuditLog::query()->where('event', 'fiscal_year.year_end_closing_posted')->sole();
    expect($audit->properties['branch_ids'])->toBe([$this->branchA->id, $this->branchB->id])
        ->and($audit->properties['voucher_numbers'])->toBe([$alpha->voucher_number, $bravo->voucher_number])
        ->and($audit->properties['net_surplus'])->toBe('400.0000')
        ->and($audit->properties['reason'])->toBe('Annual closing for FY 2026');
});

test('a second closing is rejected as already closed and creates no duplicates', function () {
    yecMapSurplus($this);
    app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');

    try {
        app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');
        $this->fail('A second closing should be rejected.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain('already closed');
    }
    expect(JournalEntry::query()->where('source_type', 'year_end_closing')->count())->toBe(2);
});

test('closing is blocked by configuration and workflow problems', function (string $case) {
    if ($case !== 'missing mapping') {
        yecMapSurplus($this);
    }
    if ($case === 'unposted journal') {
        JournalEntry::query()->create([
            'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15',
            'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->january->id, 'branch_id' => $this->branchA->id,
            'narration' => 'Pending', 'status' => JournalStatus::Submitted, 'created_by' => $this->admin->id,
        ]);
    }
    if ($case === 'final period closed') {
        $this->december->update(['status' => AccountingPeriodStatus::Closed]);
    }
    if ($case === 'inactive mapped ledger') {
        $this->surplus->update(['is_active' => false]);
    }

    $preview = app(YearEndClosingService::class)->preview($this->year, $this->admin);
    expect($preview['blockers'])->toHaveCount(1);
    expect(fn () => app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026'))->toThrow(ValidationException::class);
    expect(JournalEntry::query()->where('source_type', 'year_end_closing')->exists())->toBeFalse();
})->with(['missing mapping', 'unposted journal', 'final period closed', 'inactive mapped ledger']);

test('closing requires fiscal year close journal post and all branches permissions', function () {
    yecMapSurplus($this);
    $director = User::factory()->create();
    $director->assignRole(SystemRole::Director->value);
    $director->branches()->attach([$this->branchA->id, $this->branchB->id]);
    $accountant = User::factory()->create();
    $accountant->assignRole(SystemRole::Accountant->value);
    $accountant->givePermissionTo('branches.view_all');

    expect(fn () => app(YearEndClosingService::class)->post($this->year, $director, 'Annual closing for FY 2026'))->toThrow(HttpException::class)
        ->and(fn () => app(YearEndClosingService::class)->post($this->year, $accountant, 'Annual closing for FY 2026'))->toThrow(HttpException::class)
        ->and(JournalEntry::query()->where('source_type', 'year_end_closing')->exists())->toBeFalse();
});

test('fiscal year close is refused until the closing is posted and allowed afterwards', function () {
    yecMapSurplus($this);
    $this->january->update(['status' => AccountingPeriodStatus::Closed]);
    $this->december->update(['status' => AccountingPeriodStatus::Closed]);

    try {
        app(FiscalYearService::class)->close($this->year, $this->admin);
        $this->fail('Closing the year with an unclosed income balance should be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['closing'][0])->toBe('Post the year-end closing entry before closing the fiscal year.');
    }

    $this->december->update(['status' => AccountingPeriodStatus::Open]);
    app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');
    $this->december->update(['status' => AccountingPeriodStatus::Closed]);
    app(FiscalYearService::class)->close($this->year->refresh(), $this->admin);

    expect($this->year->refresh()->status)->toBe(FiscalYearStatus::Closed);
});

test('a fiscal year without income or expense activity can still be closed', function () {
    $empty = FiscalYear::factory()->create(['name' => 'FY 2030', 'start_date' => '2030-01-01', 'end_date' => '2030-12-31', 'status' => FiscalYearStatus::Open]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $empty->id, 'start_date' => '2030-12-01', 'end_date' => '2030-12-31', 'status' => AccountingPeriodStatus::Closed]);

    app(FiscalYearService::class)->close($empty, $this->admin);

    expect($empty->refresh()->status)->toBe(FiscalYearStatus::Closed);
});

test('next year trial balance carries balance sheet balances forward after closing and balances', function () {
    yecMapSurplus($this);
    app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');
    $next = FiscalYear::factory()->create(['name' => 'FY 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $next->id, 'name' => 'January 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-01-31', 'status' => AccountingPeriodStatus::Open]);
    yecPost($this, $this->branchA, '2027-01-05', [[$this->cash, '50', '0'], [$this->feeIncome, '0', '50']], $next);

    $report = app(TrialBalanceService::class)->report($next->id, '2027-01-31', [$this->branchA->id, $this->branchB->id]);

    expect(yecTrialRow($report, $this->cash))->toBe(['debit' => '450.0000', 'credit' => '0.0000'])
        ->and(yecTrialRow($report, $this->surplus))->toBe(['debit' => '0.0000', 'credit' => '400.0000'])
        ->and(yecTrialRow($report, $this->feeIncome))->toBe(['debit' => '0.0000', 'credit' => '50.0000'])
        ->and(yecTrialRow($report, $this->rent))->toBe(['debit' => '0.0000', 'credit' => '0.0000'])
        ->and($report['prior_unclosed'])->toBe('0.0000')
        ->and($report['carried_forward'])->toBeTrue()
        ->and($report['balanced'])->toBeTrue()
        ->and($report['total_debit'])->toBe('450.0000');

    $alphaOnly = app(TrialBalanceService::class)->report($next->id, '2027-01-31', [$this->branchA->id]);
    expect(yecTrialRow($alphaOnly, $this->cash))->toBe(['debit' => '750.0000', 'credit' => '0.0000'])
        ->and($alphaOnly['balanced'])->toBeTrue();
});

test('trial balance reports earlier unclosed surplus and still balances', function () {
    $next = FiscalYear::factory()->create(['name' => 'FY 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);

    $report = app(TrialBalanceService::class)->report($next->id, '2027-01-31', [$this->branchA->id, $this->branchB->id]);

    expect($report['prior_unclosed'])->toBe('-400.0000')
        ->and(yecTrialRow($report, $this->cash))->toBe(['debit' => '400.0000', 'credit' => '0.0000'])
        ->and(yecTrialRow($report, $this->feeIncome))->toBe(['debit' => '0.0000', 'credit' => '0.0000'])
        ->and($report['total_credit'])->toBe('400.0000')
        ->and($report['balanced'])->toBeTrue();

    $this->get(route('reports.trial-balance', ['fiscal_year_id' => $next->id, 'as_of' => '2027-01-31']))
        ->assertOk()->assertSee('Surplus/(deficit) of earlier years not yet closed')->assertSee('carried forward from earlier fiscal years');
});

test('single year trial balance keeps its existing shape', function () {
    $report = app(TrialBalanceService::class)->report($this->year->id, '2026-12-31', [$this->branchA->id, $this->branchB->id]);

    expect($report['prior_unclosed'])->toBe('0.0000')
        ->and($report['carried_forward'])->toBeFalse()
        ->and($report['balanced'])->toBeTrue()
        ->and(yecTrialRow($report, $this->feeIncome))->toBe(['debit' => '0.0000', 'credit' => '1000.0000']);
});

test('reversing a closing journal restores the branch balances and allows a new closing', function () {
    yecMapSurplus($this);
    [$alpha] = app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');

    app(JournalReversalService::class)->reverseDirect($alpha, $this->december, '2026-12-31', 'Closing posted too early', $this->admin);

    $balances = app(YearEndClosingService::class)->balances($this->year);
    expect(array_keys($balances))->toBe([$this->branchA->id])
        ->and((string) $balances[$this->branchA->id][$this->feeIncome->id])->toBe('-1000.0000')
        ->and((string) $balances[$this->branchA->id][$this->rent->id])->toBe('300.0000');

    $again = app(YearEndClosingService::class)->post($this->year, $this->admin, 'Closing posted again after review');
    expect($again)->toHaveCount(1)
        ->and($again[0]->source_key)->toBe("year_end_closing:{$this->year->id}:branch:{$this->branchA->id}:run:2")
        ->and(app(YearEndClosingService::class)->hasUnclosedBalance($this->year))->toBeFalse();
});

test('closing pages enforce permission and branch scope', function () {
    yecMapSurplus($this);
    $director = User::factory()->create();
    $director->assignRole(SystemRole::Director->value);
    $director->branches()->attach($this->branchA->id);

    $this->actingAs(User::factory()->create())->get(route('fiscal-years.closing', $this->year))->assertForbidden();

    $this->actingAs($director)->get(route('fiscal-years.closing', $this->year))
        ->assertOk()->assertSee('Alpha Branch')->assertDontSee('Bravo Branch')->assertDontSee('Post closing entries');
    $this->post(route('fiscal-years.closing.store', $this->year), ['reason' => 'Annual closing for FY 2026'])->assertForbidden();

    $this->actingAs($this->admin)->get(route('fiscal-years.closing', $this->year))
        ->assertOk()->assertSee('Alpha Branch')->assertSee('Bravo Branch')->assertSee('700.0000')->assertSee('Post closing entries');
    $this->get(route('fiscal-years.show', $this->year))->assertSee(route('fiscal-years.closing', $this->year));
    $this->post(route('fiscal-years.closing.store', $this->year), ['reason' => 'short'])->assertSessionHasErrors('reason');
    expect(JournalEntry::query()->where('source_type', 'year_end_closing')->exists())->toBeFalse();

    $this->post(route('fiscal-years.closing.store', $this->year), ['reason' => 'Annual closing for FY 2026'])
        ->assertRedirect(route('fiscal-years.closing', $this->year))->assertSessionHas('status', '2 year-end closing journals posted.');
    $this->post(route('fiscal-years.closing.store', $this->year), ['reason' => 'Annual closing for FY 2026'])->assertSessionHasErrors('closing');
    expect(JournalEntry::query()->where('source_type', 'year_end_closing')->where('status', 'posted')->count())->toBe(2);
});

test('performance reports keep the closed year income and expenditure after closing and after a reversal', function () {
    yecMapSurplus($this);
    $filters = ['fiscal_year_id' => $this->year->id];
    $snapshot = fn (): array => [
        'income_expenditure' => app(IncomeExpenditureStatement::class)->table(app(IncomeExpenditureStatement::class)->build($this->admin, [...$filters, 'compare' => 'previous_period']))->rows,
        'branch' => app(BranchIncomeExpenditureStatement::class)->table(app(BranchIncomeExpenditureStatement::class)->build($this->admin, $filters))->rows,
        'service' => app(ServiceAnalysisStatement::class)->table(app(ServiceAnalysisStatement::class)->build($this->admin, $filters))->rows,
        'dashboard' => collect(app(DashboardService::class)->overview($this->admin)['figures'])->whereIn('key', ['income', 'expense', 'surplus'])->pluck('value', 'key')->all(),
    ];
    $before = $snapshot();

    [$alpha] = app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');
    $report = app(IncomeExpenditureStatement::class)->build($this->admin, $filters);

    expect($report['total_income']['current'])->toBe('1200.0000')
        ->and($report['total_expenses']['current'])->toBe('800.0000')
        ->and($report['surplus']['current'])->toBe('400.0000')
        ->and($before['dashboard'])->toBe(['income' => '1200.0000', 'expense' => '800.0000', 'surplus' => '400.0000'])
        ->and($snapshot())->toBe($before);

    app(JournalReversalService::class)->reverseDirect($alpha, $this->december, '2026-12-31', 'Closing posted too early', $this->admin);
    expect($snapshot())->toBe($before);
});

test('budget versus actual keeps its actuals after closing', function () {
    $this->seed(AppSettingSeeder::class);
    yecMapSurplus($this);
    $this->branchB->update(['status' => 'publish']);
    $approver = User::factory()->administrator()->create();
    $approver->assignRole(SystemRole::Administrator->value);
    $budgets = app(BudgetService::class);
    $budget = $budgets->create([
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branchB->id, 'service_id' => null, 'name' => 'Bravo operating budget',
        'lines' => [['account_id' => $this->rent->id, 'accounting_period_id' => $this->december->id, 'amount' => '1000.0000']],
    ], $this->admin);
    $budgets->submit($budget, $this->admin);
    $this->actingAs($approver);
    $budget = $budgets->approve($budget->fresh(), $approver);
    $this->actingAs($this->admin);
    $actual = fn (): string => collect(app(BudgetReportService::class)->versusActual($budget->fresh(), $this->admin, null, null)['rows'])
        ->first(fn (array $row): bool => $row['account']->id === $this->rent->id)['actual'];

    expect($actual())->toBe('500.0000');
    app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');
    expect($actual())->toBe('500.0000');
});

test('balance sheet stays balanced after closing with the surplus in the mapped ledger', function () {
    yecMapSurplus($this);
    $statement = app(BalanceSheetStatement::class);
    $before = $statement->build($this->admin, ['as_of' => '2026-12-31']);

    app(YearEndClosingService::class)->post($this->year, $this->admin, 'Annual closing for FY 2026');
    $after = $statement->build($this->admin, ['as_of' => '2026-12-31']);
    $surplusRow = collect(AccountTree::exportRows($after['equity']))->first(fn (array $row): bool => str_contains((string) $row['account'], '311001'));

    expect($before['balanced'])->toBeTrue()
        ->and($before['unclosed_surplus'])->not->toBe('0.0000')
        ->and($after['balanced'])->toBeTrue()
        ->and($after['unclosed_surplus'])->toBe('0.0000')
        ->and($after['total_equity'])->toBe($before['unclosed_surplus'])
        ->and($after['total_equity_with_surplus'])->toBe($before['total_equity_with_surplus'])
        ->and($after['total_assets'])->toBe($before['total_assets'])
        ->and($surplusRow['balance'])->toBe($before['unclosed_surplus']);
});
