<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Reports\Statements\BalanceSheetStatement;
use App\Services\Reports\Statements\BranchIncomeExpenditureStatement;
use App\Services\Reports\Statements\IncomeExpenditureStatement;
use App\Services\Reports\Statements\ServiceAnalysisStatement;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->lahore = Branch::factory()->create(['name' => 'Lahore Campus']);
    $this->karachi = Branch::factory()->create(['name' => 'Karachi Campus']);
    $this->admin = statementUser(SystemRole::Administrator, []);
    $this->accountant = statementUser(SystemRole::Accountant, [$this->lahore]);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'FY 2026 fixture period', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
    $this->previousYear = FiscalYear::factory()->create(['name' => 'FY 2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Open]);
    $this->previousPeriod = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->previousYear->id, 'name' => 'FY 2025 fixture period', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => AccountingPeriodStatus::Open]);
    $this->january = ['from' => '2026-01-01', 'to' => '2026-01-31'];
});

/** @param list<Branch> $branches */
function statementUser(SystemRole $role, array $branches): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    foreach ($branches as $branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

/** Class → group → sub-group → ledger for every account type. */
function statementChart(object $test): void
{
    $chart = [
        'asset' => ['1', 'Assets', [['111', 'Cash and Bank', ['111001' => 'Cash in Hand', '111002' => 'Bank Account']]]],
        'liability' => ['2', 'Liabilities', [['211', 'Payables', ['211001' => 'Accounts Payable']]]],
        'equity' => ['3', 'Funds', [['311', 'General Funds', ['311001' => 'General Fund']]]],
        'income' => ['4', 'Income', [['411', 'Operating Income', ['411001' => 'Fee Income', '411002' => 'Donations']]]],
        'expense' => ['5', 'Expenses', [['511', 'Staff Costs', ['511001' => 'Salaries', '511002' => 'Staff Training']], ['512', 'Premises', ['512001' => 'Rent']]]],
    ];
    $test->accounts = [];
    foreach ($chart as $type => [$code, $name, $subGroups]) {
        $accountType = AccountType::from($type);
        $base = ['account_type' => $accountType, 'normal_balance' => $accountType->defaultNormalBalance(), 'is_postable' => false];
        $class = Account::factory()->create([...$base, 'code' => $code, 'name' => $name, 'level' => AccountLevel::ClassLevel]);
        $group = Account::factory()->create([...$base, 'code' => $code.'1', 'name' => $name.' Group', 'level' => AccountLevel::Group, 'parent_id' => $class->id]);
        foreach ($subGroups as [$subCode, $subName, $ledgers]) {
            $subGroup = Account::factory()->create([...$base, 'code' => $subCode, 'name' => $subName, 'level' => AccountLevel::SubGroup, 'parent_id' => $group->id]);
            foreach ($ledgers as $ledgerCode => $ledgerName) {
                $test->accounts[$ledgerCode] = Account::factory()->create([...$base, 'is_postable' => true, 'code' => (string) $ledgerCode, 'name' => $ledgerName, 'level' => AccountLevel::Ledger, 'parent_id' => $subGroup->id]);
            }
        }
    }
}

/**
 * A journal written straight into the requested status (posted by default).
 *
 * @param  list<array{0: string, 1: string, 2: string, 3?: int|null}>  $lines  [ledger code, debit, credit, service id]
 * @param  array<string, mixed>  $overrides
 */
function statementJournal(object $test, string $date, array $lines, array $overrides = []): JournalEntry
{
    $status = $overrides['status'] ?? JournalStatus::Posted;
    unset($overrides['status']);
    $previous = $date < '2026-01-01';
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->firstOrFail()->id,
        'transaction_date' => $date,
        'fiscal_year_id' => $previous ? $test->previousYear->id : $test->year->id,
        'accounting_period_id' => $previous ? $test->previousPeriod->id : $test->period->id,
        'branch_id' => $test->lahore->id,
        'narration' => 'Statement fixture',
        'status' => JournalStatus::Draft,
        'created_by' => $test->admin->id,
        ...$overrides,
    ]);
    foreach ($lines as $index => $line) {
        $journal->lines()->create(['account_id' => $test->accounts[$line[0]]->id, 'line_number' => $index + 1, 'debit' => $line[1], 'credit' => $line[2], 'service_id' => $line[3] ?? null]);
    }
    if ($status !== JournalStatus::Draft) {
        $journal->update(['status' => $status]);
    }

    return $journal;
}

/** January 2026 activity in two branches, plus unposted journals that must never count. */
function statementFixture(object $test): void
{
    statementChart($test);
    $test->speech = FrcService::factory()->create(['name' => 'Speech Therapy']);
    $test->readiness = FrcService::factory()->create(['name' => 'School Readiness Program']);
    $test->occupational = FrcService::factory()->create(['name' => 'Occupational Therapy']);
    $karachi = ['branch_id' => $test->karachi->id];

    statementJournal($test, '2026-01-01', [['111001', '1000.0000', '0'], ['311001', '0', '1000.0000']], ['source_system' => 'accounting', 'source_type' => 'opening_balance', 'source_key' => 'opening_balance:fixture']);
    statementJournal($test, '2026-01-10', [['111001', '500.1234', '0'], ['411001', '0', '500.1234', $test->speech->id]]);
    statementJournal($test, '2026-01-15', [['111002', '300.0001', '0'], ['411001', '0', '300.0001', $test->readiness->id]]);
    statementJournal($test, '2026-01-20', [['511001', '200.5000', '0', $test->speech->id], ['111001', '0', '200.5000']]);
    statementJournal($test, '2026-01-31', [['512001', '100.0000', '0'], ['211001', '0', '100.0000']]);
    statementJournal($test, '2026-01-31', [['111001', '50.0000', '0'], ['411002', '0', '50.0000']]);
    statementJournal($test, '2026-01-12', [['111001', '400.0000', '0'], ['411001', '0', '400.0000', $test->speech->id]], $karachi);
    statementJournal($test, '2026-01-18', [['512001', '150.2500', '0'], ['111001', '0', '150.2500']], $karachi);

    foreach ([JournalStatus::Draft, JournalStatus::Submitted, JournalStatus::Approved, JournalStatus::Rejected] as $status) {
        statementJournal($test, '2026-01-15', [['111001', '9999.0000', '0'], ['411001', '0', '9999.0000', $test->speech->id]], ['status' => $status]);
    }
}

/** @return array<string, array<string, mixed>> Tree rows keyed by account code. */
function statementRows(array $tree): array
{
    return collect($tree['rows'])->keyBy('code')->all();
}

function statementDownload(object $test, User $user, string $report, string $format, array $query = []): string
{
    $response = $test->actingAs($user)->get(route('statements.export', ['report' => $report, 'format' => $format] + $query));
    $response->assertOk();

    return $response->streamedContent();
}

/** @return list<string> */
function statementScreens(): array
{
    return ['statements.income-expenditure', 'statements.balance-sheet', 'statements.branch-income-expenditure', 'statements.service-analysis'];
}

describe('with posted journals', function () {
    beforeEach(fn () => statementFixture($this));

    test('income and expenditure totals and surplus keep four decimal precision and ignore unposted journals', function () {
        $report = app(IncomeExpenditureStatement::class)->build($this->admin, $this->january);

        expect($report['total_income']['current'])->toBe('1250.1235')
            ->and($report['total_expenses']['current'])->toBe('450.7500')
            ->and($report['surplus']['current'])->toBe('799.3735')
            ->and($report['columns'])->toBe(['current'])
            ->and($report['scope']->consolidated)->toBeTrue();

        $this->actingAs($this->admin)->get(route('statements.income-expenditure', $this->january))->assertOk()
            ->assertSee('1,250.12')->assertSee('450.75')->assertSee('799.37')->assertSee('Total Income')->assertSee('Surplus')
            ->assertSee('Export CSV')->assertDontSee('9,999')->assertDontSee('Comparative period');
    });

    test('parents roll up their ledgers once and ledgers without activity are hidden', function () {
        $report = app(IncomeExpenditureStatement::class)->build($this->admin, $this->january);
        $income = statementRows($report['income']);
        $expenses = statementRows($report['expenses']);

        expect(array_column($report['income']['rows'], 'code'))->toBe(['4', '41', '411', '411001', '411002'])
            ->and($income['411001']['amounts']['current'])->toBe('1200.1235')
            ->and($income['411002']['amounts']['current'])->toBe('50.0000')
            ->and($income['411']['amounts']['current'])->toBe('1250.1235')
            ->and($income['41']['amounts']['current'])->toBe('1250.1235')
            ->and($income['4']['amounts']['current'])->toBe('1250.1235')
            ->and($income['4']['depth'])->toBe(0)->and($income['411001']['depth'])->toBe(3)
            // Staff Training has no activity; its sub-group stays because Salaries has.
            ->and(array_column($report['expenses']['rows'], 'code'))->toBe(['5', '51', '511', '511001', '512', '512001'])
            ->and($expenses['511']['amounts']['current'])->toBe('200.5000')
            ->and($expenses['512']['amounts']['current'])->toBe('250.2500')
            ->and($expenses['5']['amounts']['current'])->toBe('450.7500');

        $ledgerSum = collect($report['expenses']['rows'])->where('is_ledger', true)->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['amounts']['current'], 4), '0');
        expect($ledgerSum)->toBe($report['total_expenses']['current']);
    });

    test('a reversed journal and its reversal net to zero in range and show separately at the date boundary', function () {
        $original = statementJournal($this, '2026-02-10', [['111001', '70.0000', '0'], ['411001', '0', '70.0000']], ['status' => JournalStatus::Reversed]);
        statementJournal($this, '2026-03-05', [['411001', '70.0000', '0'], ['111001', '0', '70.0000']], ['reversal_of_id' => $original->id]);
        $statement = app(IncomeExpenditureStatement::class);

        $both = $statement->build($this->admin, ['from' => '2026-02-01', 'to' => '2026-03-31']);
        expect($both['total_income']['current'])->toBe('0.0000')
            ->and(statementRows($both['income'])['411001']['amounts']['current'])->toBe('0.0000')
            ->and($statement->build($this->admin, ['from' => '2026-02-01', 'to' => '2026-02-28'])['total_income']['current'])->toBe('70.0000')
            ->and($statement->build($this->admin, ['from' => '2026-03-01', 'to' => '2026-03-31'])['total_income']['current'])->toBe('-70.0000')
            ->and($statement->build($this->admin, ['from' => '2026-02-10', 'to' => '2026-03-04'])['total_income']['current'])->toBe('70.0000')
            ->and($statement->build($this->admin, ['from' => '2026-02-11', 'to' => '2026-03-05'])['total_income']['current'])->toBe('-70.0000');
    });

    test('from and to dates are inclusive and the next day is excluded', function () {
        $statement = app(IncomeExpenditureStatement::class);

        $inclusive = $statement->build($this->admin, ['from' => '2026-01-10', 'to' => '2026-01-20']);
        $narrower = $statement->build($this->admin, ['from' => '2026-01-11', 'to' => '2026-01-19']);

        expect($inclusive['total_income']['current'])->toBe('1200.1235')
            ->and($inclusive['total_expenses']['current'])->toBe('350.7500')
            ->and($narrower['total_income']['current'])->toBe('700.0001')
            ->and($narrower['total_expenses']['current'])->toBe('150.2500');

        $this->actingAs($this->admin)->get(route('statements.income-expenditure', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
    });

    test('the balance sheet as-of date includes the boundary day and excludes the next', function () {
        $statement = app(BalanceSheetStatement::class);
        $before = $statement->build($this->admin, ['as_of' => '2026-01-30']);
        $on = $statement->build($this->admin, ['as_of' => '2026-01-31']);

        expect($before['total_liabilities'])->toBe('0.0000')
            ->and($before['total_assets'])->toBe('1849.3735')
            ->and($before['unclosed_surplus'])->toBe('849.3735')
            ->and($before['balanced'])->toBeTrue()
            ->and($on['total_liabilities'])->toBe('100.0000')
            ->and($on['total_assets'])->toBe('1899.3735');
    });

    test('a fiscal year cuts off at its dates and a comparison appears only when requested and real', function () {
        $statement = app(IncomeExpenditureStatement::class);

        // Requested, but nothing was posted in the earlier year: no comparison column is invented.
        $empty = $statement->build($this->admin, ['fiscal_year_id' => $this->year->id, 'compare' => 'previous_year']);
        expect($empty['columns'])->toBe(['current'])->and($empty['comparative'])->toBeNull()->and($empty['comparative_notice'])->toContain('no comparison is shown');

        statementJournal($this, '2025-12-31', [['111001', '25.0000', '0'], ['411002', '0', '25.0000']]);

        $current = $statement->build($this->admin, ['fiscal_year_id' => $this->year->id]);
        expect($current['total_income']['current'])->toBe('1250.1235')
            ->and($current['columns'])->toBe(['current'])
            ->and($current['period']['from'])->toBe('2026-01-01')->and($current['period']['to'])->toBe('2026-12-31')
            ->and($statement->build($this->admin, ['fiscal_year_id' => $this->previousYear->id])['total_income']['current'])->toBe('25.0000')
            // With no filter the current fiscal year is used.
            ->and($statement->build($this->admin, [])['period']['fiscal_year']->id)->toBe($this->year->id);

        $compared = $statement->build($this->admin, ['fiscal_year_id' => $this->year->id, 'compare' => 'previous_year']);
        expect($compared['columns'])->toBe(['current', 'comparative'])
            ->and($compared['total_income'])->toBe(['current' => '1250.1235', 'comparative' => '25.0000'])
            ->and($compared['surplus']['comparative'])->toBe('25.0000')
            ->and($compared['comparative']['from'])->toBe('2025-01-01');

        $previousPeriod = $statement->build($this->admin, [...$this->january, 'compare' => 'previous_period']);
        expect($previousPeriod['comparative']['from'])->toBe('2025-12-01')->and($previousPeriod['comparative']['to'])->toBe('2025-12-31')
            ->and($previousPeriod['total_income']['comparative'])->toBe('25.0000');

        $this->actingAs($this->admin)->get(route('statements.income-expenditure', ['fiscal_year_id' => $this->year->id, 'compare' => 'previous_year']))
            ->assertOk()->assertSee('Comparative period')->assertSee('25.00');
    });

    test('the consolidated balance sheet balances with opening balances and the unclosed surplus line', function () {
        $report = app(BalanceSheetStatement::class)->build($this->admin, ['as_of' => '2026-01-31']);
        $assets = statementRows($report['assets']);

        expect($report['total_assets'])->toBe('1899.3735')
            ->and($assets['111001']['amounts']['balance'])->toBe('1599.3734')
            ->and($assets['111002']['amounts']['balance'])->toBe('300.0001')
            ->and($assets['1']['amounts']['balance'])->toBe('1899.3735')
            ->and($report['total_liabilities'])->toBe('100.0000')
            ->and($report['total_equity'])->toBe('1000.0000')
            ->and($report['unclosed_surplus'])->toBe('799.3735')
            ->and($report['total_equity_with_surplus'])->toBe('1799.3735')
            ->and($report['total_liabilities_and_equity'])->toBe('1899.3735')
            ->and($report['difference'])->toBe('0.0000')
            ->and($report['balanced'])->toBeTrue()
            ->and($report['limitation'])->toBeNull()
            ->and($report['has_opening_balance'])->toBeTrue();

        $this->actingAs($this->admin)->get(route('statements.balance-sheet', ['as_of' => '2026-01-31']))->assertOk()
            ->assertSee('Balanced')->assertSee(BalanceSheetStatement::UNCLOSED_LABEL)->assertSee('1,899.37')->assertSee('799.37')
            ->assertSee('Consolidated view')->assertDontSee('OUT OF BALANCE')->assertDontSee('opening data may be incomplete');
    });

    test('an unbalanced ledger is reported as out of balance and never plugged', function () {
        statementJournal($this, '2026-01-25', [['111001', '10.0000', '0']]);
        $report = app(BalanceSheetStatement::class)->build($this->admin, ['as_of' => '2026-01-31']);

        expect($report['balanced'])->toBeFalse()
            ->and($report['difference'])->toBe('10.0000')
            ->and($report['total_assets'])->toBe('1909.3735')
            ->and($report['total_liabilities_and_equity'])->toBe('1899.3735')
            ->and($report['unclosed_surplus'])->toBe('799.3735')
            ->and($report['limitation'])->toBeNull();

        $this->actingAs($this->admin)->get(route('statements.balance-sheet', ['as_of' => '2026-01-31']))->assertOk()
            ->assertSee('OUT OF BALANCE by 10.00')->assertDontSee(BalanceSheetStatement::BRANCH_LIMITATION);
    });

    test('a missing opening balance journal is disclosed', function () {
        $report = app(BalanceSheetStatement::class)->build($this->admin, ['as_of' => '2026-01-31', 'branch_id' => $this->karachi->id]);

        expect($report['has_opening_balance'])->toBeFalse()->and($report['balanced'])->toBeTrue();
        $this->actingAs($this->admin)->get(route('statements.balance-sheet', ['as_of' => '2026-01-31', 'branch_id' => $this->karachi->id]))->assertOk()
            ->assertSee('historical opening data may be incomplete')->assertSee('Branch view');
        // Before the opening journal's date the consolidated view has none either.
        expect(app(BalanceSheetStatement::class)->build($this->admin, ['as_of' => '2025-12-31'])['has_opening_balance'])->toBeFalse();
    });

    test('a branch whose own entries do not balance is labelled as an incomplete branch view', function () {
        // One organisation-level transfer recorded as two one-sided branch entries.
        statementJournal($this, '2026-01-22', [['111001', '60.0000', '0']]);
        statementJournal($this, '2026-01-22', [['211001', '0', '60.0000']], ['branch_id' => $this->karachi->id]);
        $statement = app(BalanceSheetStatement::class);

        $consolidated = $statement->build($this->admin, ['as_of' => '2026-01-31']);
        $branch = $statement->build($this->admin, ['as_of' => '2026-01-31', 'branch_id' => $this->lahore->id]);

        expect($consolidated['balanced'])->toBeTrue()->and($consolidated['limitation'])->toBeNull()
            ->and($branch['balanced'])->toBeFalse()
            ->and($branch['difference'])->toBe('60.0000')
            ->and($branch['limitation'])->toBe(BalanceSheetStatement::BRANCH_LIMITATION)
            ->and($branch['scope']->consolidated)->toBeFalse();

        $this->actingAs($this->admin)->get(route('statements.balance-sheet', ['as_of' => '2026-01-31', 'branch_id' => $this->lahore->id]))->assertOk()
            ->assertSee(BalanceSheetStatement::BRANCH_LIMITATION)->assertSee('OUT OF BALANCE by 60.00');
    });

    test('a branch that balances on its own carries no limitation label', function () {
        $branch = app(BalanceSheetStatement::class)->build($this->accountant, ['as_of' => '2026-01-31']);

        expect($branch['balanced'])->toBeTrue()->and($branch['limitation'])->toBeNull()
            ->and($branch['scope']->consolidated)->toBeFalse()
            ->and($branch['total_assets'])->toBe('1649.6235')
            ->and($branch['unclosed_surplus'])->toBe('549.6235');
    });

    test('branch columns add up to the total', function () {
        $report = app(BranchIncomeExpenditureStatement::class)->build($this->admin, $this->january);
        $lahore = 'branch_'.$this->lahore->id;
        $karachi = 'branch_'.$this->karachi->id;

        expect($report['branch_columns'])->toBe([$karachi => 'Karachi Campus', $lahore => 'Lahore Campus'])
            ->and($report['total_label'])->toBe('Total FRC (all branches)')
            ->and($report['total_income'])->toBe([$karachi => '400.0000', $lahore => '850.1235', 'total' => '1250.1235'])
            ->and($report['total_expenses'])->toBe([$karachi => '150.2500', $lahore => '300.5000', 'total' => '450.7500'])
            ->and($report['surplus'])->toBe([$karachi => '249.7500', $lahore => '549.6235', 'total' => '799.3735'])
            ->and(bcadd($report['surplus'][$karachi], $report['surplus'][$lahore], 4))->toBe($report['surplus']['total']);

        foreach ([...$report['income']['rows'], ...$report['expenses']['rows']] as $row) {
            expect(bcadd($row['amounts'][$karachi], $row['amounts'][$lahore], 4))->toBe($row['amounts']['total']);
        }
        // The total equals the consolidated Income & Expenditure statement.
        expect($report['surplus']['total'])->toBe(app(IncomeExpenditureStatement::class)->build($this->admin, $this->january)['surplus']['current']);

        $this->actingAs($this->admin)->get(route('statements.branch-income-expenditure', $this->january))->assertOk()
            ->assertSee('Lahore Campus')->assertSee('Karachi Campus')->assertSee('Total FRC (all branches)')->assertSee('549.62')->assertSee('249.75');
    });

    test('a user limited to some branches sees only those and an honestly labelled total', function () {
        $report = app(BranchIncomeExpenditureStatement::class)->build($this->accountant, $this->january);

        expect(array_values($report['branch_columns']))->toBe(['Lahore Campus'])
            ->and($report['total_label'])->toBe('Total of shown branches')
            ->and($report['surplus']['total'])->toBe('549.6235');

        $this->actingAs($this->accountant)->get(route('statements.branch-income-expenditure', $this->january))->assertOk()
            ->assertSee('Total of shown branches')->assertDontSee('Total FRC (all branches)')->assertDontSee('Karachi Campus')->assertDontSee('249.75');
    });

    test('services get only their own lines and unallocated amounts are never redistributed', function () {
        $report = app(ServiceAnalysisStatement::class)->build($this->admin, $this->january);
        $income = collect($report['income']['services'])->keyBy('name');
        $expenses = collect($report['expenses']['services'])->keyBy('name');
        $summary = collect($report['summary'])->keyBy('name');

        expect($income['Speech Therapy']['amount'])->toBe('900.1234')
            ->and($income['School Readiness Program']['amount'])->toBe('300.0001')
            ->and($income['Occupational Therapy']['amount'])->toBeNull()
            ->and($report['income']['unallocated']['amount'])->toBe('50.0000')
            ->and($report['income']['allocated_total'])->toBe('1200.1235')
            ->and($report['income']['total'])->toBe('1250.1235')
            ->and($expenses['Speech Therapy']['amount'])->toBe('200.5000')
            ->and($expenses['School Readiness Program']['amount'])->toBeNull()
            ->and($report['expenses']['unallocated']['amount'])->toBe('250.2500')
            ->and($report['expenses']['unallocated']['ledgers'])->toHaveCount(1)
            ->and($report['expenses']['total'])->toBe('450.7500')
            ->and($summary['Speech Therapy'])->toMatchArray(['income' => '900.1234', 'expenses' => '200.5000', 'result' => '699.6234'])
            ->and($summary['School Readiness Program'])->toMatchArray(['income' => '300.0001', 'expenses' => null, 'result' => '300.0001'])
            ->and($summary['Occupational Therapy']['result'])->toBeNull()
            // Shared costs stay out of the direct results.
            ->and($report['direct_result_total'])->toBe('999.6235')
            ->and($report['overall_result'])->toBe('799.3735');

        $this->actingAs($this->admin)->get(route('statements.service-analysis', $this->january))->assertOk()
            ->assertSee('Unallocated / shared')->assertSee('direct result before unallocated shared costs')->assertSee('not profitability')
            ->assertSee('School Readiness Program')->assertSee('699.62')->assertSee('250.25');

        $lahoreOnly = app(ServiceAnalysisStatement::class)->build($this->accountant, $this->january);
        expect(collect($lahoreOnly['income']['services'])->keyBy('name')['Speech Therapy']['amount'])->toBe('500.1234');
    });

    test('statements need the view permission', function () {
        $cashier = statementUser(SystemRole::Cashier, [$this->lahore]);

        foreach (statementScreens() as $route) {
            $this->actingAs($cashier)->get(route($route))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, $this->january))->assertOk();
        }
        $this->actingAs($cashier)->get(route('statements.export', ['report' => 'balance-sheet', 'format' => 'csv']))->assertForbidden();
        expect(fn () => app(IncomeExpenditureStatement::class)->build($cashier, $this->january))->toThrow(HttpException::class);
    });

    test('another branch and the all-branches view are refused without access', function () {
        foreach (['statements.income-expenditure', 'statements.balance-sheet', 'statements.service-analysis'] as $route) {
            $this->actingAs($this->accountant)->get(route($route, [...$this->january, 'branch_id' => $this->karachi->id]))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, [...$this->january, 'branch_id' => 'all']))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, [...$this->january, 'branch_id' => $this->lahore->id]))->assertOk();
            $this->actingAs($this->admin)->get(route($route, [...$this->january, 'branch_id' => 'all']))->assertOk();
        }

        // Without a branch filter the user gets only their own branches, never a silent consolidation.
        $own = app(IncomeExpenditureStatement::class)->build($this->accountant, $this->january);
        expect($own['surplus']['current'])->toBe('549.6235')->and($own['scope']->consolidated)->toBeFalse()->and($own['scope']->label)->toContain('Lahore Campus');
    });

    test('exports need the export permission and respect branch access', function () {
        $approver = statementUser(SystemRole::Approver, [$this->lahore]);

        foreach (['income-expenditure', 'balance-sheet', 'branch-income-expenditure', 'service-analysis'] as $report) {
            $this->actingAs($approver)->get(route('statements.export', ['report' => $report, 'format' => 'csv'] + $this->january))->assertForbidden();
        }
        $this->actingAs($approver)->get(route('statements.income-expenditure', $this->january))->assertOk()->assertDontSee('Export CSV');
        $this->actingAs($this->accountant)->get(route('statements.export', ['report' => 'income-expenditure', 'format' => 'csv', 'branch_id' => $this->karachi->id] + $this->january))->assertForbidden();
        $this->actingAs($this->accountant)->get(route('statements.export', ['report' => 'balance-sheet', 'format' => 'pdf', 'branch_id' => 'all']))->assertForbidden();
        $this->actingAs($this->admin)->get(route('statements.export', ['report' => 'balance-sheet', 'format' => 'docx']))->assertNotFound();
    });

    test('the income and expenditure csv export carries the figures, filters and branch scope', function () {
        $csv = statementDownload($this, $this->accountant, 'income-expenditure', 'csv', [...$this->january, 'branch_id' => $this->lahore->id]);

        expect($csv)->toContain('Income & Expenditure Statement')
            ->toContain('Period: 01 Jan 2026 to 31 Jan 2026')->toContain('Branch scope: Lahore Campus')
            ->toContain('"Total Income",850.1235')->toContain('"Total Expenses",300.5000')->toContain('Surplus/(Deficit),549.6235')
            ->toContain('411001 Fee Income",800.1235')->toContain('Posted journals only')
            ->not->toContain('9999')->not->toContain('Comparative');

        statementJournal($this, '2025-12-31', [['111001', '25.0000', '0'], ['411002', '0', '25.0000']]);
        $compared = statementDownload($this, $this->admin, 'income-expenditure', 'csv', [...$this->january, 'compare' => 'previous_period']);
        expect($compared)->toContain('"Total Income",1250.1235,25.0000')->toContain('Comparative period: Previous period: 01 Dec 2025 to 31 Dec 2025')
            ->toContain('Branch scope: All FRC branches (consolidated)');
    });

    test('the balance sheet xlsx export carries the balances, as-of date and balance check', function () {
        $path = tempnam(sys_get_temp_dir(), 'stm').'.xlsx';
        file_put_contents($path, statementDownload($this, $this->admin, 'balance-sheet', 'xlsx', ['as_of' => '2026-01-31']));
        $rows = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false));
        unlink($path);
        $value = fn (string $label) => $rows->first(fn (array $row): bool => $row[0] === $label)[1];
        $header = $rows->pluck(0)->implode("\n");

        expect($value('Total Assets'))->toEqual(1899.3735)
            ->and($value('Total Liabilities'))->toEqual(100)
            ->and($value('  '.BalanceSheetStatement::UNCLOSED_LABEL.' (computed)'))->toEqual(799.3735)
            ->and($value('Total Liabilities and Fund/Equity'))->toEqual(1899.3735)
            ->and($value('Difference (Assets minus Liabilities and Fund/Equity)'))->toEqual(0)
            ->and($header)->toContain('As of: 31 Jan 2026')->toContain('Branch scope: All FRC branches (consolidated)')->toContain('View: Consolidated')
            ->toContain('Note: Balanced')->not->toContain('opening data may be incomplete');
    });

    test('balance sheet exports state an imbalance, the branch limitation and missing opening data', function () {
        statementJournal($this, '2026-01-22', [['211001', '0', '60.0000']], ['branch_id' => $this->karachi->id]);
        $csv = statementDownload($this, $this->admin, 'balance-sheet', 'csv', ['as_of' => '2026-01-31', 'branch_id' => $this->karachi->id]);

        expect($csv)->toContain('OUT OF BALANCE by -60.0000')->toContain(BalanceSheetStatement::BRANCH_LIMITATION)
            ->toContain('historical opening data may be incomplete')->toContain('View: Branch view')->toContain('As of: 31 Jan 2026')
            ->toContain('"Total Liabilities",60.0000')->toContain('"Total Assets",249.7500');
    });

    test('branch-wise and service analysis exports carry their figures and limitation wording', function () {
        $pdf = statementDownload($this, $this->admin, 'branch-income-expenditure', 'pdf', $this->january);
        expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()
            ->and($pdf)->toContain('(Branch-wise Income & Expenditure)')->toContain('(Period: 01 Jan 2026 to 31 Jan 2026)')
            ->toContain('(549.62)')->toContain('(249.75)')->toContain('(799.37)')->toContain('(1,250.12)');

        $branchCsv = statementDownload($this, $this->accountant, 'branch-income-expenditure', 'csv', $this->january);
        expect($branchCsv)->toContain('Account,"Lahore Campus","Total of shown branches"')->toContain('Surplus/(Deficit),549.6235,549.6235')
            ->toContain('not of all FRC')->not->toContain('Karachi Campus');

        $csv = statementDownload($this, $this->admin, 'service-analysis', 'csv', $this->january);
        expect($csv)->toContain('direct result before unallocated shared costs')->toContain('not profitability')
            ->toContain('"  Speech Therapy",900.1234,200.5000,699.6234')
            ->toContain('"  School Readiness Program",300.0001,,300.0001')
            ->toContain('"Unallocated / shared (not distributed)",50.0000,250.2500,')
            ->toContain('"Total attributed to services",1200.1235,200.5000,999.6235')
            ->toContain('"Overall surplus/(deficit) for the period",1250.1235,450.7500,799.3735');

        $servicePdf = statementDownload($this, $this->admin, 'service-analysis', 'pdf', $this->january);
        expect($servicePdf)->toContain('(Service Analysis)')->toContain('(699.62)')->toContain('(250.25)');

        $path = tempnam(sys_get_temp_dir(), 'stm').'.xlsx';
        file_put_contents($path, statementDownload($this, $this->admin, 'service-analysis', 'xlsx', $this->january));
        $rows = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false));
        unlink($path);
        expect($rows->first(fn (array $row): bool => $row[0] === 'Overall surplus/(deficit) for the period')[3])->toEqual(799.3735);
    });

    test('no report or export creates or changes a journal', function () {
        $snapshot = fn (): array => [DB::table('journal_entries')->orderBy('id')->get()->toJson(), DB::table('journal_lines')->orderBy('id')->get()->toJson(), DB::table('accounts')->orderBy('id')->get()->toJson()];
        $before = $snapshot();

        foreach (statementScreens() as $route) {
            $this->actingAs($this->admin)->get(route($route, [...$this->january, 'as_of' => '2026-01-31', 'compare' => 'previous_period']))->assertOk();
        }
        foreach (['income-expenditure', 'balance-sheet', 'branch-income-expenditure', 'service-analysis'] as $report) {
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                statementDownload($this, $this->admin, $report, $format, [...$this->january, 'as_of' => '2026-01-31']);
            }
        }

        expect($snapshot())->toBe($before);
    });
});

test('every statement shows the setup-required state when the chart of accounts is empty', function () {
    expect(Account::query()->count())->toBe(0);

    foreach (statementScreens() as $route) {
        $this->actingAs($this->admin)->get(route($route))->assertOk()
            ->assertSee('Accounting setup required')->assertDontSee('Export CSV')->assertDontSee('Total Income')->assertDontSee('Balanced');
    }
    $this->actingAs($this->admin)->get(route('statements.export', ['report' => 'income-expenditure', 'format' => 'csv']))->assertNotFound();
});

test('a chart with no posted activity shows an honest empty state instead of zero figures', function () {
    statementChart($this);

    $this->actingAs($this->admin)->get(route('statements.income-expenditure', $this->january))->assertOk()
        ->assertSee('No posted income or expenditure')->assertDontSee('Total Income');
    $this->actingAs($this->admin)->get(route('statements.balance-sheet'))->assertOk()
        ->assertSee('No posted balances')->assertSee('historical opening data may be incomplete');
});
