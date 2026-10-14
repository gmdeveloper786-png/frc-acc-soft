<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\CashFlowActivity;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashFlowClassification;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\JournalReversalService;
use App\Services\Reports\CashFlow\CashFlowStatementService;
use App\Services\Reports\CashFlow\ReceiptsPaymentsService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['name' => 'Lahore Branch']);
    $this->otherBranch = Branch::factory()->create(['name' => 'Karachi Branch']);
    $this->admin = cashflowUser(SystemRole::Administrator);
    $this->accountant = cashflowUser(SystemRole::Accountant, $this->branch);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->january = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->february = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'February 2026', 'period_number' => 2, 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => AccountingPeriodStatus::Open]);
    $this->voucherType = VoucherType::query()->where('code', 'JV')->firstOrFail();
});

function cashflowUser(SystemRole $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    if ($branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

/** Ledgers, cash/bank accounts and an explicit classification for every non-cash ledger except Accounts Payable. */
function cashflowChart(object $test): void
{
    $ledger = fn (string $code, string $name, string $type, string $normal): Account => Account::factory()->create([
        'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
        'financial_statement' => in_array($type, ['income', 'expense'], true) ? 'income_expenditure' : 'balance_sheet',
    ]);
    $test->cash = $ledger('111001', 'Cash in Hand', 'asset', 'debit');
    $test->bank = $ledger('111002', 'Bank Current Account', 'asset', 'debit');
    $test->equipment = $ledger('121001', 'Therapy Equipment', 'asset', 'debit');
    $test->payable = $ledger('211001', 'Accounts Payable', 'liability', 'credit');
    $test->loan = $ledger('221001', 'Long Term Loan', 'liability', 'credit');
    $test->income = $ledger('411001', 'Therapy Fee Income', 'income', 'credit');
    $test->rent = $ledger('511001', 'Rent Expense', 'expense', 'debit');
    FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Main Cash', 'account_id' => $test->cash->id, 'branch_id' => $test->branch->id]);
    FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'HBL Current', 'account_id' => $test->bank->id, 'branch_id' => $test->branch->id]);
    foreach ([[$test->income, 'operating'], [$test->rent, 'operating'], [$test->equipment, 'investing'], [$test->loan, 'financing']] as [$account, $activity]) {
        CashFlowClassification::factory()->create(['account_id' => $account->id, 'activity' => $activity]);
    }
}

/**
 * A journal written straight to the ledger tables in the given status.
 *
 * @param  list<array{0: Account, 1: string, 2: string}>  $lines  [account, debit, credit]
 */
function cashflowJournal(object $test, string $date, array $lines, ?Branch $branch = null, JournalStatus $status = JournalStatus::Posted): JournalEntry
{
    static $number = 0;
    $number++;
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => $test->voucherType->id, 'transaction_date' => $date, 'fiscal_year_id' => $test->year->id,
        'accounting_period_id' => str_starts_with($date, '2026-02') ? $test->february->id : $test->january->id,
        'branch_id' => ($branch ?? $test->branch)->id, 'narration' => 'Cash flow fixture', 'status' => JournalStatus::Draft, 'created_by' => $test->admin->id,
    ]);
    foreach ($lines as $index => [$account, $debit, $credit]) {
        $journal->lines()->create(['line_number' => $index + 1, 'account_id' => $account->id, 'debit' => $debit, 'credit' => $credit]);
    }
    if ($status !== JournalStatus::Draft) {
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => $status->value, 'voucher_number' => $status === JournalStatus::Posted ? 'CF-TEST-'.$number : null]);
    }

    return $journal->fresh();
}

function cashflowStatement(object $test, string $from = '2026-01-01', string $to = '2026-01-31', ?User $actor = null, Branch|false|null $branch = false): array
{
    return app(CashFlowStatementService::class)->statement($actor ?? $test->admin, $branch === false ? $test->branch : $branch, $from, $to);
}

function cashflowReceipts(object $test, string $from = '2026-01-01', string $to = '2026-01-31'): array
{
    return app(ReceiptsPaymentsService::class)->statement($test->admin, $test->branch, $from, $to);
}

/** @return array<string, string> */
function cashflowQuery(object $test, array $extra = []): array
{
    return ['from' => '2026-01-01', 'to' => '2026-01-31', 'branch_id' => $test->branch->id, ...$extra];
}

test('an empty chart of accounts shows the setup required state instead of figures', function () {
    $this->actingAs($this->admin);

    expect(cashflowStatement($this)['state'])->toBe('setup_required');
    $this->get(route('cash-flow.statement', cashflowQuery($this)))->assertOk()->assertSee('Accounting setup required')->assertDontSee('Net change in cash')->assertDontSee('0.00');
    $this->get(route('cash-flow.receipts-payments', cashflowQuery($this)))->assertOk()->assertSee('Accounting setup required')->assertDontSee('Total receipts');
    $this->get(route('cash-flow.classification.index'))->assertOk()->assertSee('Accounting setup required');
    $this->get(route('cash-flow.statement.export', ['format' => 'csv', ...cashflowQuery($this)]))->assertStatus(409);
});

test('a chart without cash or bank ledgers is reported as setup required', function () {
    Account::factory()->create();
    $this->actingAs($this->admin);

    expect(cashflowStatement($this)['state'])->toBe('no_cash_ledgers');
    $this->get(route('cash-flow.statement', cashflowQuery($this)))->assertOk()->assertSee('No cash, bank or petty cash ledger is configured');
});

describe('with a configured chart', function () {
    beforeEach(function () {
        cashflowChart($this);
    });

    test('opening and closing position come from the posted cash ledgers and reconcile', function () {
        cashflowJournal($this, '2025-12-20', [[$this->cash, '500.0000', '0'], [$this->income, '0', '500.0000']]);
        cashflowJournal($this, '2026-01-05', [[$this->bank, '1200.5000', '0'], [$this->income, '0', '1200.5000']]);
        cashflowJournal($this, '2026-01-09', [[$this->rent, '300.2500', '0'], [$this->cash, '0', '300.2500']]);
        cashflowJournal($this, '2026-01-12', [[$this->bank, '9999.0000', '0'], [$this->income, '0', '9999.0000']], null, JournalStatus::Draft);
        cashflowJournal($this, '2026-01-13', [[$this->bank, '8888.0000', '0'], [$this->income, '0', '8888.0000']], null, JournalStatus::Approved);

        $statement = cashflowStatement($this);

        expect($statement['state'])->toBe('ready')
            ->and($statement['method'])->toBe('Direct method')
            ->and($statement['opening'])->toBe('500.0000')
            ->and($statement['sections']['operating']['inflows'])->toBe('1200.5000')
            ->and($statement['sections']['operating']['outflows'])->toBe('300.2500')
            ->and($statement['sections']['operating']['net'])->toBe('900.2500')
            ->and($statement['net_change'])->toBe('900.2500')
            ->and($statement['expected_closing'])->toBe('1400.2500')
            ->and($statement['closing'])->toBe('1400.2500')
            ->and($statement['difference'])->toBe('0.0000')
            ->and($statement['reconciled'])->toBeTrue()
            ->and($statement['is_final'])->toBeTrue()
            ->and($statement['journal_count'])->toBe(2);

        $this->actingAs($this->accountant)->get(route('cash-flow.statement', cashflowQuery($this)))->assertOk()
            ->assertSee('Direct method')->assertSee('PKR 500.00')->assertSee('PKR 1,400.25')->assertSee('1,200.50')->assertSee('300.25')->assertSee('Reconciled')
            ->assertDontSee('Not final')->assertDontSee('9,999.00')->assertDontSee('8,888.00');
    });

    test('inflows and outflows follow the configured activity of the counter ledger', function () {
        cashflowJournal($this, '2026-01-05', [[$this->bank, '1000.0000', '0'], [$this->loan, '0', '1000.0000']]);
        cashflowJournal($this, '2026-01-06', [[$this->equipment, '400.0000', '0'], [$this->bank, '0', '400.0000']]);
        cashflowJournal($this, '2026-01-07', [[$this->cash, '250.0000', '0'], [$this->income, '0', '250.0000']]);

        $sections = cashflowStatement($this)['sections'];

        expect($sections['financing'])->toMatchArray(['inflows' => '1000.0000', 'outflows' => '0.0000', 'net' => '1000.0000'])
            ->and($sections['investing'])->toMatchArray(['inflows' => '0.0000', 'outflows' => '400.0000', 'net' => '-400.0000'])
            ->and($sections['operating'])->toMatchArray(['inflows' => '250.0000', 'outflows' => '0.0000', 'net' => '250.0000'])
            ->and($sections['investing']['rows'][0]['account']->id)->toBe($this->equipment->id);

        // Reclassifying a ledger moves its cash movement; nothing is derived from its name or type.
        CashFlowClassification::query()->where('account_id', $this->equipment->id)->update(['activity' => 'operating']);
        $sections = cashflowStatement($this)['sections'];
        expect($sections['investing']['outflows'])->toBe('0.0000')->and($sections['operating']['outflows'])->toBe('400.0000');
    });

    test('a multi line journal is attributed to its counter ledgers in exact proportion', function () {
        cashflowJournal($this, '2026-01-05', [[$this->bank, '1000.0000', '0'], [$this->income, '0', '333.3333'], [$this->loan, '0', '666.6667']]);
        cashflowJournal($this, '2026-01-06', [[$this->bank, '90.0000', '0'], [$this->rent, '10.0000', '0'], [$this->income, '0', '100.0000']]);
        cashflowJournal($this, '2026-01-07', [[$this->rent, '0.0001', '0'], [$this->equipment, '0.0002', '0'], [$this->cash, '0', '0.0003']]);

        $statement = cashflowStatement($this);

        expect($statement['sections']['operating'])->toMatchArray(['inflows' => '433.3333', 'outflows' => '10.0001', 'net' => '423.3332'])
            ->and($statement['sections']['financing'])->toMatchArray(['inflows' => '666.6667', 'outflows' => '0.0000'])
            ->and($statement['sections']['investing'])->toMatchArray(['inflows' => '0.0000', 'outflows' => '0.0002'])
            ->and($statement['net_change'])->toBe('1089.9997')
            ->and($statement['closing'])->toBe('1089.9997')
            ->and($statement['reconciled'])->toBeTrue();
    });

    test('a transfer between cash and bank ledgers is excluded from inflows and outflows', function () {
        cashflowJournal($this, '2026-01-04', [[$this->cash, '800.0000', '0'], [$this->income, '0', '800.0000']]);
        cashflowJournal($this, '2026-01-08', [[$this->bank, '250.0000', '0'], [$this->cash, '0', '250.0000']]);

        $statement = cashflowStatement($this);
        $receipts = cashflowReceipts($this);

        expect($statement['sections']['operating'])->toMatchArray(['inflows' => '800.0000', 'outflows' => '0.0000'])
            ->and($statement['unclassified']['rows'])->toBe([])
            ->and($statement['net_change'])->toBe('800.0000')
            ->and($statement['transfers'])->toBe(['count' => 1, 'amount' => '250.0000'])
            ->and($statement['journal_count'])->toBe(1)
            ->and($statement['reconciled'])->toBeTrue()
            ->and($receipts['total_receipts'])->toBe('800.0000')
            ->and($receipts['total_payments'])->toBe('0.0000')
            ->and(collect($receipts['cash_accounts'])->pluck('closing', 'name')->all())->toBe(['Main Cash' => '550.0000', 'HBL Current' => '250.0000']);
    });

    test('a reversal nets its original to zero and is never shown as an inflow', function () {
        cashflowJournal($this, '2026-01-04', [[$this->cash, '1000.0000', '0'], [$this->income, '0', '1000.0000']]);
        $payment = cashflowJournal($this, '2026-01-10', [[$this->rent, '300.0000', '0'], [$this->cash, '0', '300.0000']]);
        $this->actingAs($this->admin);
        app(JournalReversalService::class)->reverse($payment, $this->february, '2026-02-03', 'Paid twice', $this->admin);

        $january = cashflowStatement($this);
        $february = cashflowStatement($this, '2026-02-01', '2026-02-28');
        $both = cashflowStatement($this, '2026-01-01', '2026-02-28');

        expect($payment->fresh()->status)->toBe(JournalStatus::Reversed)
            ->and($january['sections']['operating'])->toMatchArray(['inflows' => '1000.0000', 'outflows' => '300.0000'])
            ->and($february['sections']['operating'])->toMatchArray(['inflows' => '0.0000', 'outflows' => '-300.0000', 'net' => '300.0000'])
            ->and($february['reconciled'])->toBeTrue()
            ->and($february['closing'])->toBe('1000.0000')
            ->and($both['sections']['operating'])->toMatchArray(['inflows' => '1000.0000', 'outflows' => '0.0000', 'net' => '1000.0000'])
            ->and($both['closing'])->toBe('1000.0000')
            ->and($both['reconciled'])->toBeTrue();

        $receipts = cashflowReceipts($this, '2026-01-01', '2026-02-28');
        expect($receipts['total_receipts'])->toBe('1000.0000')
            ->and($receipts['total_payments'])->toBe('0.0000')
            ->and(collect($receipts['receipts'])->pluck('account.id')->all())->toBe([$this->income->id]);
    });

    test('a ledger difference is reported exactly and never plugged', function () {
        cashflowJournal($this, '2026-01-05', [[$this->bank, '100.0000', '0'], [$this->income, '0', '90.0000']]);

        $statement = cashflowStatement($this);

        expect($statement['reconciled'])->toBeFalse()
            ->and($statement['net_change'])->toBe('90.0000')
            ->and($statement['expected_closing'])->toBe('90.0000')
            ->and($statement['closing'])->toBe('100.0000')
            ->and($statement['difference'])->toBe('10.0000');

        $this->actingAs($this->accountant);
        $this->get(route('cash-flow.statement', cashflowQuery($this)))->assertOk()->assertSee('Not reconciled')->assertSee('10.0000')
            ->assertDontSee(route('cash-flow.statement.export', ['format' => 'csv']), false);
        $this->get(route('cash-flow.statement.export', ['format' => 'csv', ...cashflowQuery($this)]))->assertStatus(409);
        expect(fn () => app(CashFlowStatementService::class)->exportTable($statement, []))->toThrow(HttpException::class);
    });

    test('unclassified counter ledgers are listed and mark the statement as not final', function () {
        cashflowJournal($this, '2026-01-05', [[$this->bank, '900.0000', '0'], [$this->income, '0', '900.0000']]);
        cashflowJournal($this, '2026-01-06', [[$this->payable, '150.0000', '0'], [$this->bank, '0', '150.0000']]);

        $statement = cashflowStatement($this);

        expect($statement['is_final'])->toBeFalse()
            ->and($statement['classification_state'])->toBe('incomplete')
            ->and($statement['unclassified'])->toMatchArray(['inflows' => '0.0000', 'outflows' => '150.0000', 'net' => '-150.0000'])
            ->and($statement['unclassified']['rows'][0]['account']->id)->toBe($this->payable->id)
            ->and($statement['sections']['operating']['inflows'])->toBe('900.0000')
            ->and($statement['net_change'])->toBe('750.0000')
            ->and($statement['reconciled'])->toBeTrue();

        $this->actingAs($this->accountant)->get(route('cash-flow.statement', cashflowQuery($this)))->assertOk()
            ->assertSee('Not final: classification incomplete')->assertSee('Unclassified — configuration required')->assertSee('Accounts Payable')
            ->assertSee(route('cash-flow.classification.index', ['activity' => 'unclassified']), false);
        $csv = $this->get(route('cash-flow.statement.export', ['format' => 'csv', ...cashflowQuery($this)]))->assertOk()->streamedContent();
        expect($csv)->toContain('Not final: classification incomplete')->toContain('211001 Accounts Payable",0.0000,150.0000,-150.0000');

        CashFlowClassification::factory()->create(['account_id' => $this->payable->id, 'activity' => 'operating']);
        expect(cashflowStatement($this))->toMatchArray(['is_final' => true, 'classification_state' => 'complete']);
    });

    test('with no classification at all the configuration requirement replaces the activities', function () {
        CashFlowClassification::query()->delete();
        cashflowJournal($this, '2026-01-05', [[$this->bank, '900.0000', '0'], [$this->income, '0', '900.0000']]);

        $statement = cashflowStatement($this);

        expect($statement['classification_state'])->toBe('none')
            ->and($statement['is_final'])->toBeFalse()
            ->and($statement['unclassified']['inflows'])->toBe('900.0000');
        $this->actingAs($this->accountant)->get(route('cash-flow.statement', cashflowQuery($this)))->assertOk()
            ->assertSee('Configuration required: no ledger has a cash-flow classification')->assertDontSee('Net cash from operating activities');
    });

    test('date and branch boundaries are respected', function () {
        cashflowJournal($this, '2026-01-31', [[$this->cash, '100.0000', '0'], [$this->income, '0', '100.0000']]);
        cashflowJournal($this, '2026-02-01', [[$this->cash, '40.0000', '0'], [$this->income, '0', '40.0000']]);
        cashflowJournal($this, '2026-01-15', [[$this->cash, '7000.0000', '0'], [$this->income, '0', '7000.0000']], $this->otherBranch);

        $january = cashflowStatement($this);
        $february = cashflowStatement($this, '2026-02-01', '2026-02-28');
        $consolidated = cashflowStatement($this, '2026-01-01', '2026-01-31', null, null);
        $fiscalYear = $this->actingAs($this->accountant)->get(route('cash-flow.statement', ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id]));

        expect($january)->toMatchArray(['opening' => '0.0000', 'net_change' => '100.0000', 'closing' => '100.0000'])
            ->and($february)->toMatchArray(['opening' => '100.0000', 'net_change' => '40.0000', 'closing' => '140.0000'])
            ->and($consolidated)->toMatchArray(['net_change' => '7100.0000', 'closing' => '7100.0000', 'reconciled' => true]);
        $fiscalYear->assertOk()->assertSee('01 Jan 2026 to 31 Dec 2026')->assertSee('PKR 140.00')->assertDontSee('7,140.00');
    });

    test('statements require permission and never widen the branch scope', function () {
        $cashier = cashflowUser(SystemRole::Cashier, $this->branch);
        cashflowJournal($this, '2026-01-15', [[$this->cash, '7000.0000', '0'], [$this->income, '0', '7000.0000']], $this->otherBranch);
        cashflowJournal($this, '2026-01-16', [[$this->cash, '55.0000', '0'], [$this->income, '0', '55.0000']]);

        foreach (['cash-flow.statement', 'cash-flow.receipts-payments'] as $route) {
            $this->actingAs($cashier)->get(route($route, cashflowQuery($this)))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, cashflowQuery($this, ['branch_id' => $this->otherBranch->id])))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, cashflowQuery($this, ['branch_id' => 'all'])))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, ['from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk()->assertSee('Lahore Branch')->assertSee('55.00')->assertDontSee('7,055.00');
            $this->actingAs($this->admin)->get(route($route, cashflowQuery($this, ['branch_id' => 'all'])))->assertOk()->assertSee('All branches (consolidated)')->assertSee('7,055.00');
            $this->actingAs($this->accountant)->get(route($route.'.export', ['format' => 'csv', ...cashflowQuery($this, ['branch_id' => $this->otherBranch->id])]))->assertForbidden();
        }
        $this->actingAs($cashier)->get(route('cash-flow.classification.index'))->assertForbidden();

        expect(fn () => cashflowStatement($this, '2026-01-01', '2026-01-31', $this->accountant, $this->otherBranch))->toThrow(HttpException::class)
            ->and(fn () => cashflowStatement($this, '2026-01-01', '2026-01-31', $this->accountant, null))->toThrow(HttpException::class)
            ->and(fn () => cashflowStatement($this, '2026-01-01', '2026-01-31', $cashier))->toThrow(HttpException::class);

        $this->accountant->update(['is_active' => false]);
        expect(fn () => cashflowStatement($this, '2026-01-01', '2026-01-31', $this->accountant->fresh()))->toThrow(HttpException::class);
    });

    test('receipts and payments show actual cash movement only', function () {
        cashflowJournal($this, '2025-12-28', [[$this->bank, '2000.0000', '0'], [$this->income, '0', '2000.0000']]);
        cashflowJournal($this, '2026-01-05', [[$this->cash, '650.7500', '0'], [$this->income, '0', '650.7500']]);
        // Accrued expense: never touches cash, so it must not appear until it is paid.
        cashflowJournal($this, '2026-01-06', [[$this->rent, '700.0000', '0'], [$this->payable, '0', '700.0000']]);
        cashflowJournal($this, '2026-01-20', [[$this->payable, '700.0000', '0'], [$this->bank, '0', '700.0000']]);
        cashflowJournal($this, '2026-01-21', [[$this->bank, '100.0000', '0'], [$this->cash, '0', '100.0000']]);
        $refund = cashflowJournal($this, '2026-01-22', [[$this->equipment, '80.0000', '0'], [$this->cash, '0', '80.0000']]);
        $this->actingAs($this->admin);
        app(JournalReversalService::class)->reverse($refund, $this->january, '2026-01-25', 'Wrong entry', $this->admin);

        $statement = cashflowReceipts($this);

        expect($statement['opening'])->toBe('2000.0000')
            ->and(collect($statement['receipts'])->map(fn (array $row): array => [$row['account']->code, $row['account']->account_type->value, $row['amount']])->all())->toBe([['411001', 'income', '650.7500']])
            ->and(collect($statement['payments'])->map(fn (array $row): array => [$row['account']->code, $row['account']->account_type->value, $row['amount']])->all())->toBe([['211001', 'liability', '700.0000']])
            ->and($statement['total_receipts'])->toBe('650.7500')
            ->and($statement['total_payments'])->toBe('700.0000')
            ->and($statement['net'])->toBe('-49.2500')
            ->and($statement['closing'])->toBe('1950.7500')
            ->and($statement['reconciled'])->toBeTrue()
            ->and($statement['transfers']['count'])->toBe(1)
            ->and(collect($statement['cash_accounts'])->map(fn (array $row): array => [$row['name'], $row['type'], $row['opening'], $row['movement'], $row['closing']])->all())
            ->toBe([['Main Cash', 'cash', '0.0000', '550.7500', '550.7500'], ['HBL Current', 'bank', '2000.0000', '-600.0000', '1400.0000']]);

        $this->actingAs($this->accountant)->get(route('cash-flow.receipts-payments', cashflowQuery($this)))->assertOk()
            ->assertSee('Cash basis')->assertSee('PKR 650.75')->assertSee('PKR 700.00')->assertSee('PKR 1,950.75')->assertSee('Accounts Payable')
            ->assertDontSee('Rent Expense')->assertDontSee('Therapy Equipment')->assertSee('Reconciled');
    });

    test('exports need the export permission and carry the exact figures', function () {
        cashflowJournal($this, '2026-01-05', [[$this->bank, '1200.5000', '0'], [$this->income, '0', '1200.5000']]);
        cashflowJournal($this, '2026-01-09', [[$this->rent, '300.2500', '0'], [$this->cash, '0', '300.2500']]);
        $approver = cashflowUser(SystemRole::Approver, $this->branch);

        foreach (['cash-flow.statement', 'cash-flow.receipts-payments'] as $route) {
            $this->actingAs($approver)->get(route($route, cashflowQuery($this)))->assertOk()->assertDontSee(route($route.'.export', ['format' => 'csv']), false);
            $this->actingAs($approver)->get(route($route.'.export', ['format' => 'csv', ...cashflowQuery($this)]))->assertForbidden();
            $this->actingAs($this->accountant)->get(route($route, cashflowQuery($this)))->assertOk()->assertSee(route($route.'.export', ['format' => 'pdf']), false);
            $this->actingAs($this->accountant)->get(route($route.'.export', ['format' => 'docx', ...cashflowQuery($this)]))->assertNotFound();
        }

        $this->actingAs($this->accountant);
        $csv = $this->get(route('cash-flow.statement.export', ['format' => 'csv', ...cashflowQuery($this)]))->assertOk()->streamedContent();
        expect($csv)->toContain('Cash Flow Statement (Direct method)')->toContain('Method: Direct method')->toContain('Branch: Lahore Branch')
            ->toContain('Period: 01 Jan 2026 to 31 Jan 2026')->toContain('Status: Reconciled to the General Ledger')
            ->toContain('411001 Therapy Fee Income",1200.5000,0.0000,1200.5000')->toContain('511001 Rent Expense",0.0000,300.2500,-300.2500')
            ->toContain('Net cash from operating activities",1200.5000,300.2500,900.2500')->toContain('Net change in cash and bank",,,900.2500')
            ->toContain('Reconciliation difference",,,0.0000')->not->toContain('Not final');

        $path = tempnam(sys_get_temp_dir(), 'cf').'.xlsx';
        file_put_contents($path, $this->get(route('cash-flow.statement.export', ['format' => 'xlsx', ...cashflowQuery($this)]))->assertOk()->streamedContent());
        $rows = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false));
        unlink($path);
        expect($rows->first(fn (array $row): bool => $row[0] === 'Net change in cash and bank')[3])->toEqual(900.25);

        $pdf = $this->get(route('cash-flow.statement.export', ['format' => 'pdf', ...cashflowQuery($this)]))->assertOk()->streamedContent();
        expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()->and($pdf)->toContain('(1,200.50)')->toContain('(900.25)');

        $receipts = $this->get(route('cash-flow.receipts-payments.export', ['format' => 'csv', ...cashflowQuery($this)]))->assertOk()->streamedContent();
        expect($receipts)->toContain('Receipts & Payments Statement')->toContain('Basis: Cash basis')
            ->toContain('411001 Therapy Fee Income",Income,1200.5000')->toContain('511001 Rent Expense",Expense,300.2500')
            ->toContain('Total receipts",,1200.5000')->toContain('Total payments",,300.2500')->toContain('Reconciliation difference",,0.0000')
            ->and(AuditLog::query()->where('event', 'report.exported')->count())->toBe(4);
    });

    test('classification is managed with permission, validation and an audit trail', function () {
        $group = Account::factory()->create(['code' => '510000', 'name' => 'Operating Expenses', 'account_type' => 'expense', 'level' => 'group', 'is_postable' => false]);
        $inactive = Account::factory()->create(['code' => '511099', 'name' => 'Closed Ledger', 'account_type' => 'expense', 'is_active' => false]);
        $auditor = cashflowUser(SystemRole::Auditor, $this->branch);
        $update = fn (Account $account, ?string $activity) => $this->from(route('cash-flow.classification.index'))->put(route('cash-flow.classification.update', $account), ['activity' => $activity]);

        $this->actingAs($auditor);
        $this->get(route('cash-flow.classification.index'))->assertOk()->assertSee('Accounts Payable')->assertDontSee('Set activity');
        $update($this->payable, 'financing')->assertForbidden();

        $this->actingAs($this->accountant);
        $this->get(route('cash-flow.classification.index'))->assertOk()->assertSee('Accounts Payable')->assertSee('Set activity')->assertSee('1 unclassified')
            ->assertDontSee('Cash in Hand')->assertDontSee('Bank Current Account')->assertDontSee('Operating Expenses')->assertDontSee('Closed Ledger');
        $this->get(route('cash-flow.classification.index', ['activity' => 'unclassified']))->assertOk()->assertSee('Accounts Payable')->assertDontSee('Rent Expense');
        $this->get(route('cash-flow.classification.index', ['search' => 'Rent', 'activity' => 'operating']))->assertOk()->assertSee('Rent Expense')->assertDontSee('Therapy Fee Income');

        $update($this->payable, 'financing')->assertRedirect(route('cash-flow.classification.index'))->assertSessionHasNoErrors();
        $classification = CashFlowClassification::query()->where('account_id', $this->payable->id)->firstOrFail();
        $set = AuditLog::query()->where('event', 'cash_flow.classification_set')->latest('id')->firstOrFail();
        expect($classification->activity)->toBe(CashFlowActivity::Financing)
            ->and($classification->updated_by)->toBe($this->accountant->id)
            ->and($set->actor_id)->toBe($this->accountant->id)
            ->and($set->properties)->toMatchArray(['account_code' => '211001', 'previous_activity' => null, 'activity' => 'financing']);

        $update($this->payable, 'operating')->assertSessionHasNoErrors();
        expect(AuditLog::query()->where('event', 'cash_flow.classification_set')->latest('id')->first()->properties)->toMatchArray(['previous_activity' => 'financing', 'activity' => 'operating'])
            ->and(CashFlowClassification::query()->where('account_id', $this->payable->id)->count())->toBe(1);

        $update($this->payable, 'guessing')->assertSessionHasErrors('activity');
        $update($this->cash, 'operating')->assertSessionHasErrors('activity');
        $update($this->bank, 'financing')->assertSessionHasErrors('activity');
        $update($group, 'operating')->assertSessionHasErrors('activity');
        $update($inactive, 'operating')->assertSessionHasErrors('activity');
        expect(CashFlowClassification::query()->whereIn('account_id', [$this->cash->id, $this->bank->id, $group->id, $inactive->id])->count())->toBe(0)
            ->and(CashFlowClassification::query()->where('account_id', $this->payable->id)->value('activity'))->toBe(CashFlowActivity::Operating);

        $update($this->payable, null)->assertSessionHasNoErrors();
        expect(CashFlowClassification::query()->where('account_id', $this->payable->id)->exists())->toBeFalse()
            ->and(AuditLog::query()->where('event', 'cash_flow.classification_cleared')->firstOrFail()->properties)->toMatchArray(['account_code' => '211001', 'previous_activity' => 'operating']);
    });

    test('a newly created ledger is never classified automatically', function () {
        $ledger = Account::factory()->create(['code' => '121002', 'name' => 'Investment in Building', 'account_type' => 'asset']);
        cashflowJournal($this, '2026-01-05', [[$ledger, '500.0000', '0'], [$this->bank, '0', '500.0000']]);

        $statement = cashflowStatement($this);

        expect(CashFlowClassification::query()->where('account_id', $ledger->id)->exists())->toBeFalse()
            ->and($statement['sections']['investing']['outflows'])->toBe('0.0000')
            ->and($statement['unclassified']['rows'][0]['account']->id)->toBe($ledger->id)
            ->and($statement['is_final'])->toBeFalse();
    });
});
