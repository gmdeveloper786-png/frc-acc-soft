<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\BudgetStatus;
use App\Enums\DepreciationRunStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PayrollRunStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\BudgetLine;
use App\Models\DepreciationRun;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollRun;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\DashboardService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['name' => 'Lahore Branch']);
    $this->otherBranch = Branch::factory()->create(['name' => 'Karachi Branch']);
    $this->admin = dashboardUser(SystemRole::Administrator);
    $this->accountant = dashboardUser(SystemRole::Accountant, $this->branch);
});

function dashboardUser(SystemRole $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    if ($branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

/** An open fiscal year with ledgers and one cash and one bank account. */
function dashboardChart(object $test): void
{
    $start = today()->startOfYear();
    $test->year = FiscalYear::factory()->create(['name' => 'FY '.$start->year, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfYear()->toDateString(), 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $test->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $test->year->id, 'name' => 'Period 1', 'period_number' => 1, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => AccountingPeriodStatus::Open]);
    $test->date = $start->copy()->addDays(9)->toDateString();
    $test->voucherType = VoucherType::query()->where('code', 'JV')->firstOrFail();
    $ledger = fn (string $code, string $name, string $type, string $normal): Account => Account::factory()->create([
        'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
        'financial_statement' => in_array($type, ['income', 'expense'], true) ? 'income_expenditure' : 'balance_sheet',
    ]);
    $test->cash = $ledger('111001', 'Cash in Hand', 'asset', 'debit');
    $test->bank = $ledger('111002', 'Bank Current Account', 'asset', 'debit');
    $test->payable = $ledger('211001', 'Accounts Payable', 'liability', 'credit');
    $test->income = $ledger('411001', 'Therapy Fee Income', 'income', 'credit');
    $test->rent = $ledger('511001', 'Rent Expense', 'expense', 'debit');
    FinancialAccount::factory()->create(['type' => 'cash', 'account_id' => $test->cash->id, 'branch_id' => $test->branch->id]);
    FinancialAccount::factory()->create(['type' => 'bank', 'account_id' => $test->bank->id, 'branch_id' => $test->branch->id]);
}

/** @param list<array{0: Account, 1: string, 2: string}> $lines [account, debit, credit] */
function dashboardJournal(object $test, array $lines, ?Branch $branch = null, JournalStatus $status = JournalStatus::Posted, string $narration = 'Dashboard fixture'): JournalEntry
{
    static $number = 0;
    $number++;
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => $test->voucherType->id, 'transaction_date' => $test->date, 'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id,
        'branch_id' => ($branch ?? $test->branch)->id, 'narration' => $narration, 'status' => JournalStatus::Draft, 'created_by' => $test->admin->id,
    ]);
    $total = '0';
    foreach ($lines as $index => [$account, $debit, $credit]) {
        $journal->lines()->create(['line_number' => $index + 1, 'account_id' => $account->id, 'debit' => $debit, 'credit' => $credit]);
        $total = bcadd($total, $debit, 4);
    }
    $posted = in_array($status, [JournalStatus::Posted, JournalStatus::Reversed], true);
    DB::table('journal_entries')->where('id', $journal->id)->update([
        'status' => $status->value, 'total_debit' => $total, 'total_credit' => $total,
        'voucher_number' => $posted ? 'DB-TEST-'.$number : null, 'posted_at' => $posted ? now() : null,
    ]);

    return $journal->fresh();
}

/** Posted cash, bank, income and expense activity of the Lahore branch. */
function dashboardActivity(object $test): void
{
    dashboardJournal($test, [[$test->cash, '1500.5000', '0'], [$test->income, '0', '1500.5000']], null, JournalStatus::Posted, 'Fee collection in cash');
    dashboardJournal($test, [[$test->bank, '2000.0000', '0'], [$test->income, '0', '2000.0000']]);
    dashboardJournal($test, [[$test->rent, '300.2500', '0'], [$test->bank, '0', '300.2500']]);
}

/** @return array<string, ?string> */
function dashboardFigures(User $user): array
{
    return collect(app(DashboardService::class)->overview($user)['figures'])->pluck('value', 'key')->all();
}

test('an empty system shows setup required and unavailable states instead of zeros', function () {
    $overview = app(DashboardService::class)->overview($this->admin);

    expect($overview['chart_is_empty'])->toBeTrue()
        ->and(collect($overview['figures'])->pluck('value')->filter(fn ($value): bool => $value !== null)->all())->toBe([])
        ->and(collect($overview['figures'])->pluck('key')->all())->toBe(['cash_balance', 'bank_balance', 'income', 'expense', 'surplus', 'payables', 'budget'])
        ->and($overview['recent_journals'])->toHaveCount(0);

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Accounting setup required')->assertSee('Amount unavailable')->assertSee('No posted journals yet')
        ->assertSee('Chart of Accounts imported')
        ->assertDontSee('PKR 0')->assertDontSee('0.00')->assertDontSee('placeholder-chart', false);
});

test('a chart without cash accounts or an open year reports those figures as not available', function () {
    Account::factory()->create();

    expect(dashboardFigures($this->admin))->toMatchArray(['cash_balance' => null, 'bank_balance' => null, 'income' => null, 'expense' => null, 'budget' => null, 'payables' => '0.0000']);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('No cash or petty cash account is configured.')->assertSee('No fiscal year is open.')->assertSee('No posted purchase bills.');
});

test('figures are exact and come from posted records only', function () {
    dashboardChart($this);
    dashboardActivity($this);
    dashboardJournal($this, [[$this->cash, '9999.0000', '0'], [$this->income, '0', '9999.0000']], null, JournalStatus::Draft);
    dashboardJournal($this, [[$this->bank, '8888.0000', '0'], [$this->income, '0', '8888.0000']], null, JournalStatus::Submitted);
    dashboardJournal($this, [[$this->rent, '7777.0000', '0'], [$this->bank, '0', '7777.0000']], null, JournalStatus::Approved);
    $bill = PurchaseBill::factory()->create(['branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id, 'total_amount' => '1000.0000', 'status' => 'posted']);
    $secondBill = PurchaseBill::factory()->create(['branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id, 'total_amount' => '250.7500', 'status' => 'posted']);
    PurchaseBill::factory()->create(['branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id, 'total_amount' => '5000.0000', 'status' => 'draft']);
    PurchaseBill::factory()->create(['branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id, 'total_amount' => '6000.0000', 'status' => 'approved']);
    $payment = ['type' => OperationalTransactionType::VendorPayment, 'branch_id' => $this->branch->id, 'purchase_bill_id' => $bill->id];
    OperationalTransaction::factory()->create([...$payment, 'amount' => '400.0000', 'status' => OperationalTransactionStatus::Posted]);
    OperationalTransaction::factory()->create([...$payment, 'amount' => '100.0000', 'status' => OperationalTransactionStatus::Approved]);
    OperationalTransaction::factory()->create([...$payment, 'amount' => '50.0000', 'status' => OperationalTransactionStatus::Reversed]);

    expect(dashboardFigures($this->accountant))->toMatchArray([
        'cash_balance' => '1500.5000', 'bank_balance' => '1699.7500', 'income' => '3500.5000', 'expense' => '300.2500', 'surplus' => '3200.2500', 'payables' => '850.7500',
    ])->and(bcadd($bill->outstandingAmount(), $secondBill->outstandingAmount(), 4))->toBe('850.7500');

    $this->actingAs($this->accountant)->get(route('dashboard'))->assertOk()
        ->assertSee('PKR 1,500.50')->assertSee('PKR 1,699.75')->assertSee('PKR 3,500.50')->assertSee('PKR 300.25')->assertSee('PKR 3,200.25')->assertSee('PKR 850.75')
        ->assertSee('Fee collection in cash')->assertSee('Lahore Branch')
        ->assertDontSee('9,999.00')->assertDontSee('8,888.00')->assertDontSee('7,777.00')->assertDontSee('Accounting setup required');
});

test('a reversed journal and its reversal net to zero', function () {
    dashboardChart($this);
    dashboardActivity($this);
    $original = dashboardJournal($this, [[$this->rent, '120.0000', '0'], [$this->cash, '0', '120.0000']], null, JournalStatus::Reversed);
    $reversal = dashboardJournal($this, [[$this->cash, '120.0000', '0'], [$this->rent, '0', '120.0000']]);
    DB::table('journal_entries')->where('id', $reversal->id)->update(['reversal_of_id' => $original->id]);

    expect(dashboardFigures($this->accountant))->toMatchArray(['cash_balance' => '1500.5000', 'expense' => '300.2500']);
});

test('a user only sees the figures of their own branches', function () {
    dashboardChart($this);
    dashboardActivity($this);
    dashboardJournal($this, [[$this->cash, '7000.0000', '0'], [$this->income, '0', '7000.0000']], $this->otherBranch, JournalStatus::Posted, 'Karachi fee collection');
    PurchaseBill::factory()->create(['branch_id' => $this->otherBranch->id, 'payable_account_id' => $this->payable->id, 'total_amount' => '640.0000', 'status' => 'posted']);
    $karachi = dashboardUser(SystemRole::Accountant, $this->otherBranch);

    expect(dashboardFigures($this->accountant))->toMatchArray(['cash_balance' => '1500.5000', 'income' => '3500.5000', 'payables' => '0.0000'])
        ->and(dashboardFigures($karachi))->toMatchArray(['cash_balance' => '7000.0000', 'bank_balance' => '0.0000', 'income' => '7000.0000', 'expense' => '0.0000', 'payables' => '640.0000'])
        ->and(dashboardFigures($this->admin))->toMatchArray(['cash_balance' => '8500.5000', 'income' => '10500.5000', 'payables' => '640.0000']);

    $this->actingAs($this->accountant)->get(route('dashboard'))->assertOk()->assertSee('PKR 1,500.50')->assertSee('Lahore Branch')
        ->assertDontSee('Karachi fee collection')->assertDontSee('7,000.00')->assertDontSee('8,500.50');
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('PKR 8,500.50')->assertSee('All branches')->assertSee('Karachi fee collection');
});

test('pending approvals are counted per module, branch and permission without payroll amounts', function () {
    dashboardChart($this);
    foreach ([$this->branch, $this->branch, $this->otherBranch] as $branch) {
        dashboardJournal($this, [[$this->cash, '10.0000', '0'], [$this->income, '0', '10.0000']], $branch, JournalStatus::Submitted);
    }
    OperationalTransaction::factory()->create(['type' => OperationalTransactionType::BankPayment, 'status' => OperationalTransactionStatus::Submitted, 'branch_id' => $this->branch->id]);
    OperationalTransaction::factory()->create(['type' => OperationalTransactionType::Expense, 'status' => OperationalTransactionStatus::Submitted, 'branch_id' => $this->branch->id]);
    OperationalTransaction::factory()->create(['type' => OperationalTransactionType::Expense, 'status' => OperationalTransactionStatus::Draft, 'branch_id' => $this->branch->id]);
    OperationalTransaction::factory()->create(['type' => OperationalTransactionType::Expense, 'status' => OperationalTransactionStatus::Submitted, 'branch_id' => $this->otherBranch->id]);
    PurchaseOrder::factory()->create(['branch_id' => $this->branch->id, 'status' => 'submitted']);
    PurchaseOrder::factory()->create(['branch_id' => $this->branch->id, 'status' => 'draft']);
    PurchaseBill::factory()->count(2)->create(['branch_id' => $this->branch->id, 'status' => 'submitted']);
    DepreciationRun::factory()->create(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id, 'status' => DepreciationRunStatus::Submitted]);
    Budget::factory()->create(['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'status' => BudgetStatus::Submitted]);
    PayrollRun::factory()->create(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id, 'status' => PayrollRunStatus::Submitted, 'gross_amount' => '987654.3200', 'total_deductions' => '111111.1100', 'net_amount' => '876543.2100']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    InventoryMovement::query()->create(['item_id' => InventoryItem::factory()->create()->id, 'branch_id' => $this->branch->id, 'location_id' => $location->id, 'type' => 'adjustment', 'quantity' => '-2.0000', 'transaction_date' => $this->date, 'status' => 'pending', 'created_by' => $this->admin->id]);

    $approver = dashboardUser(SystemRole::Approver, $this->branch);
    $pending = app(DashboardService::class)->overview($approver)['pending'];

    expect(collect($pending['items'])->pluck('count', 'label')->all())->toBe([
        'Journals' => 2, 'Payments and expenses' => 2, 'Purchase orders' => 1, 'Purchase bills' => 2, 'Depreciation runs' => 1, 'Budgets' => 1, 'Payroll runs' => 1, 'Inventory adjustments' => 1,
    ])->and($pending['total'])->toBe(11)
        ->and(app(DashboardService::class)->overview($this->admin)['pending']['total'])->toBe(13);

    $html = $this->actingAs($approver)->get(route('dashboard'))->assertOk()->assertSee('Payroll runs')->assertSee('11 waiting')->getContent();
    expect($html)->not->toContain('987,654')->not->toContain('987654')->not->toContain('876,543')->not->toContain('876543')->not->toContain('111,111');

    // A cashier may view payments and expenses but not journals, bills, budgets, payroll or inventory.
    $cashier = dashboardUser(SystemRole::Cashier, $this->branch);
    expect(collect(app(DashboardService::class)->overview($cashier)['pending']['items'])->pluck('count', 'label')->all())->toBe(['Payments and expenses' => 2]);
    $this->actingAs($cashier)->get(route('dashboard'))->assertOk()->assertDontSee('Payroll runs')->assertDontSee('Purchase bills');
});

test('figures are hidden from users without the matching permission', function () {
    dashboardChart($this);
    dashboardActivity($this);
    $cashier = dashboardUser(SystemRole::Cashier, $this->branch);
    $overview = app(DashboardService::class)->overview($cashier);

    expect($overview['figures'])->toBe([])
        ->and($overview['recent_journals'])->toBeNull()
        ->and($overview['setup'])->toMatchArray(['ready' => false, 'detailed' => false, 'checks' => []]);

    $this->actingAs($cashier)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Cash Balance')->assertDontSee('Bank Balance')->assertDontSee('Payables Outstanding')->assertDontSee('Budget Utilisation')->assertDontSee('Net Surplus')
        ->assertDontSee('1,500.50')->assertDontSee('Fee collection in cash')->assertDontSee('Fee revenue mappings valid')
        ->assertSee('Accounting setup is incomplete');

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Fee revenue mappings valid')->assertSee('Cash Balance');

    $custom = User::factory()->create();
    $custom->givePermissionTo(['dashboard.view', 'finance_reports.view']);
    $custom->branches()->attach($this->branch);
    expect(array_keys(dashboardFigures($custom)))->toBe(['payables']);

    $custom->update(['is_active' => false]);
    app(DashboardService::class)->overview($custom->fresh());
})->throws(HttpException::class);

test('budget utilisation comes from the approved budget and records nothing', function () {
    dashboardChart($this);
    dashboardActivity($this);
    expect(dashboardFigures($this->accountant)['budget'])->toBeNull();

    $budget = Budget::factory()->create(['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'status' => BudgetStatus::Draft, 'total_amount' => '1000.0000', 'created_by' => $this->admin->id]);
    BudgetLine::query()->create(['budget_id' => $budget->id, 'account_id' => $this->rent->id, 'accounting_period_id' => $this->period->id, 'amount' => '1000.0000']);
    expect(dashboardFigures($this->accountant)['budget'])->toBeNull();

    $budget->update(['status' => BudgetStatus::Approved]);
    $journals = JournalEntry::query()->count();

    expect(dashboardFigures($this->accountant)['budget'])->toBe('30.03')
        ->and(BudgetAlert::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe($journals);
    $this->actingAs($this->accountant)->get(route('dashboard'))->assertOk()->assertSee('30.03%');
    $this->actingAs(dashboardUser(SystemRole::Accountant, $this->otherBranch))->get(route('dashboard'))->assertOk()->assertDontSee('30.03%')->assertSee('No approved budget');
});

test('the dashboard renders for every seeded role', function (SystemRole $role) {
    dashboardChart($this);
    dashboardActivity($this);

    $this->actingAs(dashboardUser($role, $this->branch))->get(route('dashboard'))->assertOk()->assertSee('Accounting Dashboard')->assertSee('Pending Approvals');
})->with(SystemRole::cases());

test('the dashboard requires the dashboard permission', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertForbidden();
    auth()->logout();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
