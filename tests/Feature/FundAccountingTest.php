<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Funds\FundBalanceSummary;
use App\Services\Funds\FundIncomeExpenditureReport;
use App\Services\Funds\FundRestrictionPolicy;
use App\Services\Funds\GrantUtilisationReport;
use App\Services\Funds\WorkbookFundCodes;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OperationalTransactionService;
use App\Services\Reports\Statements\IncomeExpenditureStatement;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Database\Seeders\WorkbookFundCodeSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->lahore = Branch::factory()->create(['name' => 'Lahore Campus', 'status' => 'publish']);
    $this->karachi = Branch::factory()->create(['name' => 'Karachi Campus', 'status' => 'publish']);
    $this->admin = fundUser(SystemRole::Administrator, []);
    $this->checker = fundUser(SystemRole::Administrator, []);
    $this->poster = fundUser(SystemRole::Administrator, []);
    $this->accountant = fundUser(SystemRole::Accountant, [$this->lahore]);
    $this->director = fundUser(SystemRole::Director, [$this->lahore]);
    $this->year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year->id, 'name' => 'FY 2026 fixture period', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
    $this->cashLedger = Account::factory()->create(['code' => '111101', 'name' => 'Cash in Hand']);
    $this->equipment = Account::factory()->create(['code' => '121101', 'name' => 'Equipment']);
    $this->donations = Account::factory()->create(['code' => '431001', 'name' => 'Donations', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->grantIncome = Account::factory()->create(['code' => '432001', 'name' => 'Grant Income', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->salaries = Account::factory()->create(['code' => '511001', 'name' => 'Salaries', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->medicine = Account::factory()->create(['code' => '512001', 'name' => 'Patient Medicine', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->lahore->id, 'account_id' => $this->cashLedger->id]);
});

/** @param list<Branch> $branches */
function fundUser(SystemRole $role, array $branches): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    foreach ($branches as $branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

/**
 * A journal written straight into the posted status.
 *
 * @param  list<array{0: Account, 1: string, 2: string, 3?: Fund|null}>  $lines  [account, debit, credit, fund]
 */
function fundJournal(object $test, string $date, array $lines, ?Branch $branch = null, array $overrides = []): JournalEntry
{
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->firstOrFail()->id, 'transaction_date' => $date,
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id, 'branch_id' => ($branch ?? $test->lahore)->id,
        'narration' => 'Fund fixture', 'status' => JournalStatus::Draft, 'created_by' => $test->admin->id, ...$overrides,
    ]);
    foreach ($lines as $index => $line) {
        $journal->lines()->create(['account_id' => $line[0]->id, 'line_number' => $index + 1, 'debit' => $line[1], 'credit' => $line[2], 'fund_id' => ($line[3] ?? null)?->id]);
    }
    $journal->update(['status' => JournalStatus::Posted]);

    return $journal;
}

/** Posted January activity across two funds, no fund, and two branches. */
function fundFixture(object $test): void
{
    $test->zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat / Charity Fund']);
    $test->grant = Fund::factory()->grant('1000.0000')->create(['code' => 'GRT-01', 'name' => 'Clinic Grant', 'donor_name' => 'Example Trust']);
    fundJournal($test, '2026-01-05', [[$test->cashLedger, '500.1234', '0', $test->zakat], [$test->donations, '0', '500.1234', $test->zakat]]);
    fundJournal($test, '2026-01-06', [[$test->cashLedger, '600.1234', '0', $test->grant], [$test->grantIncome, '0', '600.1234', $test->grant]]);
    fundJournal($test, '2026-01-07', [[$test->medicine, '250.5678', '0', $test->grant], [$test->cashLedger, '0', '250.5678', $test->grant]]);
    fundJournal($test, '2026-01-08', [[$test->salaries, '100.0001', '0', $test->zakat], [$test->cashLedger, '0', '100.0001', $test->zakat]]);
    fundJournal($test, '2026-01-09', [[$test->cashLedger, '75.5000', '0'], [$test->donations, '0', '75.5000']]);
    fundJournal($test, '2026-01-10', [[$test->salaries, '40.0000', '0'], [$test->cashLedger, '0', '40.0000']]);
    // Karachi activity, outside the accountant's branch access.
    fundJournal($test, '2026-01-11', [[$test->medicine, '999.0000', '0', $test->grant], [$test->cashLedger, '0', '999.0000', $test->grant]], $test->karachi);
}

test('funds are created and edited with audit, and only by fund managers', function () {
    $this->actingAs($this->accountant)->post(route('funds.store'), [
        'code' => 'grt-01', 'name' => 'Clinic Grant', 'type' => 'restricted', 'category' => 'grant', 'is_active' => '1',
        'donor_name' => 'Example Trust', 'grant_amount' => '2500000.5000', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'permitted_account_ids' => [$this->medicine->id],
    ])->assertRedirect(route('funds.index'));

    $fund = Fund::query()->where('code', 'GRT-01')->firstOrFail();
    expect($fund->grant_amount)->toBe('2500000.5000')->and($fund->created_by)->toBe($this->accountant->id)
        ->and($fund->permittedAccounts->pluck('id')->all())->toBe([$this->medicine->id])
        ->and(AuditLog::query()->where('event', 'funds.created')->where('auditable_id', $fund->id)->exists())->toBeTrue();

    $this->put(route('funds.update', $fund), ['code' => 'GRT-01', 'name' => 'Clinic Grant 2026', 'type' => 'restricted', 'category' => 'grant', 'is_active' => '1', 'grant_amount' => '2600000', 'permitted_account_ids' => []])->assertRedirect();
    $log = AuditLog::query()->where('event', 'funds.updated')->latest('id')->firstOrFail();
    expect($fund->fresh()->name)->toBe('Clinic Grant 2026')->and($fund->fresh()->permittedAccounts)->toHaveCount(0)
        ->and($log->properties['changes']['grant_amount'])->toBe(['old' => '2500000.5000', 'new' => '2600000.0000']);

    $this->post(route('funds.store'), ['code' => 'GRT-01', 'name' => 'Duplicate', 'type' => 'restricted', 'category' => 'grant'])->assertSessionHasErrors('code');
    $this->post(route('funds.store'), ['code' => 'BAD', 'name' => 'Bad ledger', 'type' => 'restricted', 'category' => 'donor', 'permitted_account_ids' => [$this->donations->id]])->assertSessionHasErrors('permitted_account_ids');

    $auditor = fundUser(SystemRole::Auditor, [$this->lahore]);
    $this->actingAs($auditor)->get(route('funds.index'))->assertOk()->assertSee('GRT-01')->assertDontSee('+ New fund');
    $this->post(route('funds.store'), ['code' => 'X1', 'name' => 'X', 'type' => 'unrestricted', 'category' => 'general'])->assertForbidden();
    $this->put(route('funds.update', $fund), ['name' => 'Hijack'])->assertForbidden();
    $this->actingAs(fundUser(SystemRole::Cashier, [$this->lahore]))->get(route('funds.index'))->assertForbidden();
});

test('a user holding only grants.manage changes the grant fields and nothing else', function () {
    $fund = Fund::factory()->grant('1000.0000')->create(['code' => 'GRT-02', 'name' => 'Original name']);
    $grantClerk = User::factory()->create();
    $grantClerk->givePermissionTo(['funds.view', 'grants.manage']);

    $this->actingAs($grantClerk)->put(route('funds.update', $fund), ['code' => 'HACK', 'name' => 'Renamed', 'type' => 'unrestricted', 'category' => 'general', 'donor_name' => 'New Donor', 'grant_amount' => '1500.2500', 'start_date' => '2026-02-01', 'end_date' => '2026-11-30'])->assertRedirect();

    $fund->refresh();
    expect($fund->code)->toBe('GRT-02')->and($fund->name)->toBe('Original name')->and($fund->type)->toBe(Fund::TYPE_RESTRICTED)
        ->and($fund->donor_name)->toBe('New Donor')->and($fund->grant_amount)->toBe('1500.2500')->and($fund->end_date->toDateString())->toBe('2026-11-30');
    $this->post(route('funds.store'), ['code' => 'NEW', 'name' => 'New', 'type' => 'unrestricted', 'category' => 'general'])->assertForbidden();
});

test('workbook fund codes load on request only, exactly as in the workbook, once', function () {
    expect(Fund::query()->count())->toBe(0);
    Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund (edited locally)']);

    $this->actingAs($this->accountant)->get(route('funds.index'))->assertOk()->assertSee('Load workbook fund codes');
    $this->post(route('funds.workbook-codes'))->assertRedirect()->assertSessionHas('status', 'Workbook fund codes loaded: DNR, ZKT, GRT, CAP.');

    $funds = Fund::query()->orderBy('code')->get()->keyBy('code');
    expect($funds->keys()->all())->toBe(['CAP', 'DNR', 'GEN', 'GRT', 'ZKT'])
        ->and($funds['GEN']->name)->toBe('General Fund (edited locally)')
        ->and($funds['ZKT']->only(['name', 'type', 'category']))->toBe(['name' => 'Zakat / Charity Fund', 'type' => 'restricted', 'category' => 'zakat'])
        ->and($funds['ZKT']->restriction_notes)->toContain('[Confirm]')
        ->and($funds['GRT']->category)->toBe('grant')
        ->and(Fund::query()->where('code', 'like', 'GRT-%')->exists())->toBeFalse();

    $this->post(route('funds.workbook-codes'))->assertSessionHas('status', 'Every workbook fund code already exists; nothing was changed.');
    expect(Fund::query()->count())->toBe(5)->and(AuditLog::query()->where('event', 'funds.created')->count())->toBe(4);
    $this->get(route('funds.index'))->assertDontSee('Load workbook fund codes');

    $this->actingAs(fundUser(SystemRole::Auditor, []))->post(route('funds.workbook-codes'))->assertForbidden();
});

test('the test seeder loads the five workbook codes', function () {
    $this->seed(WorkbookFundCodeSeeder::class);

    expect(Fund::query()->orderBy('code')->pluck('code')->all())->toBe(['CAP', 'DNR', 'GEN', 'GRT', 'ZKT'])
        ->and(app(WorkbookFundCodes::class)->missing())->toBe([]);
});

test('a donation inherits its category fund unless another fund is chosen, and posted lines carry it', function () {
    $zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT']);
    $general = Fund::factory()->create(['code' => 'GEN']);
    $category = DonationCategory::query()->where('name', 'Zakat')->firstOrFail();
    $this->actingAs($this->admin)->put(route('donation-categories.update', $category), ['income_account_id' => $this->donations->id, 'fund_id' => $zakat->id, 'is_active' => '1'])->assertRedirect();
    expect($category->fresh()->fund_id)->toBe($zakat->id);

    $service = app(OperationalTransactionService::class);
    $payload = ['type' => 'donation', 'branch_id' => $this->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '250.1234', 'financial_account_id' => $this->cash->id, 'donation_category_id' => $category->id, 'narration' => 'Zakat donation'];
    $inherited = $service->post($service->create($payload, $this->admin), $this->admin);
    $chosen = $service->create([...$payload, 'fund_id' => $general->id], $this->admin);

    expect($inherited->fund_id)->toBe($zakat->id)->and($chosen->fund_id)->toBe($general->id)
        ->and($inherited->journalEntry->lines->pluck('fund_id')->unique()->all())->toBe([$zakat->id]);

    $plain = $service->create(['type' => 'cash_receipt', 'branch_id' => $this->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '10', 'financial_account_id' => $this->cash->id, 'contra_account_id' => $this->donations->id, 'narration' => 'No fund'], $this->admin);
    expect($service->post($plain, $this->admin)->journalEntry->lines->pluck('fund_id')->unique()->all())->toBe([null]);
});

test('the transaction form offers an optional fund and stores it', function () {
    $fund = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund']);
    Fund::factory()->create(['code' => 'OLD', 'is_active' => false]);

    $this->actingAs($this->admin)->get(route('transactions.create', 'cash_receipt'))->assertOk()->assertSee('Fund (optional)')->assertSee('GEN — General Fund')->assertDontSee('OLD —');
    $payload = ['type' => 'cash_receipt', 'branch_id' => $this->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '12.5000', 'financial_account_id' => $this->cash->id, 'contra_account_id' => $this->donations->id, 'narration' => 'Fund receipt'];
    $this->post(route('transactions.store'), [...$payload, 'fund_id' => Fund::query()->where('code', 'OLD')->value('id')])->assertSessionHasErrors('fund_id');
    $this->post(route('transactions.store'), [...$payload, 'fund_id' => $fund->id])->assertRedirect();

    expect(OperationalTransaction::query()->latest('id')->value('fund_id'))->toBe($fund->id);
});

test('manual journal lines carry an optional fund through posting and reversal', function () {
    $fund = Fund::factory()->restricted('donor')->create(['code' => 'DNR']);
    $fund->permittedAccounts()->sync([$this->equipment->id]); // the donor-restricted fund may buy equipment
    $payload = [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15',
        'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->period->id, 'branch_id' => $this->lahore->id, 'narration' => 'Donor-restricted receipt',
        'lines' => [
            ['account_id' => $this->equipment->id, 'debit' => '300.0000', 'credit' => '0', 'fund_id' => $fund->id],
            ['account_id' => $this->donations->id, 'debit' => '0', 'credit' => '300.0000', 'fund_id' => $fund->id],
            ['account_id' => $this->salaries->id, 'debit' => '1.0000', 'credit' => '0'],
            ['account_id' => $this->donations->id, 'debit' => '0', 'credit' => '1.0000'],
        ],
    ];
    $this->actingAs($this->admin)->get(route('journals.create'))->assertOk()->assertSee('lines[0][fund_id]', false);
    $this->post(route('journals.store'), $payload)->assertRedirect();
    $journal = JournalEntry::query()->latest('id')->firstOrFail();
    expect($journal->lines()->orderBy('line_number')->pluck('fund_id')->all())->toBe([$fund->id, $fund->id, null, null]);

    $workflow = app(JournalWorkflowService::class);
    $workflow->submit($journal, $this->admin);
    $this->actingAs($this->checker);
    $workflow->approve($journal->fresh(), $this->checker);
    $this->actingAs($this->poster)->post(route('journals.post', $journal))->assertRedirect();
    expect($journal->fresh()->status)->toBe(JournalStatus::Posted);
    $this->get(route('journals.show', $journal))->assertOk()->assertSee('DNR');
    expect(fn () => $journal->lines()->first()->update(['fund_id' => null]))->toThrow(LogicException::class);

    $reversal = app(JournalReversalService::class)->reverse($journal->fresh(), $this->period, '2026-01-20', 'Reverse the fund fixture', $this->checker);
    expect($reversal->lines()->orderBy('line_number')->pluck('fund_id')->all())->toBe([$fund->id, $fund->id, null, null]);
});

function fundPayment(object $test, string $type, Account $ledger, ?Fund $fund): array
{
    return ['type' => $type, 'branch_id' => $test->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '50.0000', 'financial_account_id' => $test->cash->id, 'contra_account_id' => $ledger->id, 'fund_id' => $fund?->id, 'narration' => 'Fund-tagged charge'];
}

function fundJournalData(object $test, Account $ledger, ?Fund $fund, string $narration = 'Fund journal'): array
{
    return ['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15', 'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id, 'branch_id' => $test->lahore->id, 'narration' => $narration,
        'lines' => [['account_id' => $ledger->id, 'debit' => '5.0000', 'credit' => '0', 'fund_id' => $fund?->id], ['account_id' => $test->donations->id, 'debit' => '0', 'credit' => '5.0000']]];
}

test('a restricted fund is charged only to its permitted ledgers on payments, expenses, petty cash and journals', function (string $type) {
    $zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT']);
    $zakat->permittedAccounts()->sync([$this->medicine->id]);
    $service = app(OperationalTransactionService::class);
    $this->actingAs($this->admin);

    expect($service->create(fundPayment($this, $type, $this->medicine, $zakat), $this->admin)->exists)->toBeTrue();
    expect(fn () => $service->create(fundPayment($this, $type, $this->salaries, $zakat), $this->admin))
        ->toThrow(ValidationException::class, 'Ledger 511001 Salaries is not a permitted charge for restricted fund ZKT.');
})->with(['cash_payment', 'expense', 'petty_cash']);

test('manual journals honour the restricted-fund rule when saved and again when posted', function () {
    $zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT']);
    $zakat->permittedAccounts()->sync([$this->medicine->id, $this->salaries->id]);
    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($this->admin);

    expect(fn () => $workflow->create(fundJournalData($this, $this->equipment, $zakat), $this->admin))
        ->toThrow(ValidationException::class, 'Ledger 121101 Equipment is not a permitted charge for restricted fund ZKT.');

    // Saved while permitted, then the fund's permitted ledgers change: submission and posting are refused.
    $draft = $workflow->create(fundJournalData($this, $this->salaries, $zakat, 'Salaries charged to Zakat'), $this->admin);
    $zakat->permittedAccounts()->sync([$this->medicine->id]);
    $this->get(route('journals.show', $draft))->assertOk()->assertSee('Restricted-fund rule.')->assertSee('not a permitted charge for restricted fund ZKT');
    expect(fn () => $workflow->submit($draft, $this->admin))->toThrow(ValidationException::class);

    $zakat->permittedAccounts()->sync([$this->medicine->id, $this->salaries->id]);
    $workflow->submit($draft->fresh(), $this->admin);
    $approved = $workflow->approve($draft->fresh(), $this->checker);
    $zakat->permittedAccounts()->sync([$this->medicine->id]);
    expect(fn () => app(JournalPostingService::class)->post($approved->fresh(), $this->poster))
        ->toThrow(ValidationException::class, 'not a permitted charge for restricted fund ZKT');
    expect($approved->fresh()->status)->toBe(JournalStatus::Approved);
});

test('a restricted fund without permitted ledgers cannot be charged until it is configured', function () {
    $grant = Fund::factory()->restricted('grant')->create(['code' => 'GRT-01']);
    $this->actingAs($this->admin);
    $setupError = 'Restricted fund GRT-01 has no permitted ledgers configured, so nothing can be charged to it.';

    expect(fn () => app(OperationalTransactionService::class)->create(fundPayment($this, 'expense', $this->medicine, $grant), $this->admin))->toThrow(ValidationException::class, $setupError)
        ->and(fn () => app(JournalWorkflowService::class)->create(fundJournalData($this, $this->medicine, $grant), $this->admin))->toThrow(ValidationException::class, $setupError);

    // Money received into the fund is not a charge, so it is allowed before configuration.
    $receipt = app(OperationalTransactionService::class)->create(['type' => 'cash_receipt', 'branch_id' => $this->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '500.0000', 'financial_account_id' => $this->cash->id, 'contra_account_id' => $this->grantIncome->id, 'fund_id' => $grant->id, 'narration' => 'Grant instalment'], $this->admin);
    expect(app(OperationalTransactionService::class)->post($receipt, $this->admin)->journalEntry->lines->every(fn ($line): bool => (int) $line->fund_id === $grant->id))->toBeTrue();

    $grant->permittedAccounts()->sync([$this->medicine->id]);
    expect(app(OperationalTransactionService::class)->create(fundPayment($this, 'expense', $this->medicine, $grant), $this->admin)->exists)->toBeTrue();
});

test('unrestricted funds and untagged lines are not limited, and reversals of restricted charges are never blocked', function () {
    $general = Fund::factory()->create(['code' => 'GEN', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general']);
    $zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT']);
    $zakat->permittedAccounts()->sync([$this->medicine->id]);
    $service = app(OperationalTransactionService::class);
    $this->actingAs($this->admin);

    expect($service->create(fundPayment($this, 'expense', $this->salaries, $general), $this->admin)->exists)->toBeTrue()
        ->and($service->create(fundPayment($this, 'expense', $this->salaries, null), $this->admin)->exists)->toBeTrue();

    $workflow = app(JournalWorkflowService::class);
    $draft = $workflow->create(fundJournalData($this, $this->medicine, $zakat, 'Permitted Zakat charge'), $this->admin);
    $workflow->submit($draft, $this->admin);
    $posted = app(JournalPostingService::class)->post($workflow->approve($draft->fresh(), $this->checker)->fresh(), $this->poster);
    $zakat->permittedAccounts()->sync([]);
    $reversal = app(JournalReversalService::class)->reverse($posted->fresh(), $this->period, '2026-01-20', 'Charged to the wrong fund', $this->checker);
    expect($reversal->status)->toBe(JournalStatus::Posted);
});

test('the restricted-fund rule has no switch to turn it off', function () {
    expect(Route::has('funds.restriction-policy'))->toBeFalse()
        ->and(method_exists(FundRestrictionPolicy::class, 'setEnforced'))->toBeFalse();
    $this->actingAs($this->admin)->get(route('funds.index'))->assertOk()->assertSee('Always enforced')->assertDontSee('Warn only');
});

test('fund-wise income and expenditure totals equal the plain statement, with a No fund column', function () {
    fundFixture($this);
    $filters = ['branch_id' => (string) $this->lahore->id, 'from' => '2026-01-01', 'to' => '2026-01-31'];
    $report = app(FundIncomeExpenditureReport::class)->build($this->admin, $filters);
    $plain = app(IncomeExpenditureStatement::class)->build($this->admin, $filters);

    $zakat = 'fund_'.$this->zakat->id;
    $grant = 'fund_'.$this->grant->id;
    expect($report['columns'])->toBe([$grant, $zakat, 'none', 'total'])
        ->and($report['total_income'])->toBe([$grant => '600.1234', $zakat => '500.1234', 'none' => '75.5000', 'total' => '1175.7468'])
        ->and($report['total_expenses'])->toBe([$grant => '250.5678', $zakat => '100.0001', 'none' => '40.0000', 'total' => '390.5679'])
        ->and($report['surplus']['total'])->toBe('785.1789')
        ->and($report['total_income']['total'])->toBe($plain['total_income']['current'])
        ->and($report['total_expenses']['total'])->toBe($plain['total_expenses']['current'])
        ->and($report['surplus']['total'])->toBe($plain['surplus']['current']);

    $filtered = app(FundIncomeExpenditureReport::class)->build($this->admin, [...$filters, 'fund_id' => $this->grant->id]);
    expect($filtered['columns'])->toBe([$grant, 'total'])->and($filtered['total_expenses']['total'])->toBe('250.5678');

    $this->actingAs($this->admin)->get(route('fund-reports.income-expenditure', $filters))->assertOk()->assertSee('No fund')->assertSee('GRT-01')->assertSee('1,175.75');
    $csv = $this->get(route('fund-reports.export', ['report' => 'income-expenditure', 'format' => 'csv'] + $filters))->assertOk()->streamedContent();
    expect($csv)->toContain('Fund-wise Income & Expenditure')->toContain('1175.7468');
});

test('the fund balance summary is exact for the period and cumulatively', function () {
    fundFixture($this);
    fundJournal($this, '2026-02-03', [[$this->medicine, '9.4322', '0', $this->grant], [$this->cashLedger, '0', '9.4322', $this->grant]]);
    $report = app(FundBalanceSummary::class)->build($this->admin, ['branch_id' => (string) $this->lahore->id, 'from' => '2026-02-01', 'to' => '2026-02-28']);
    $rows = collect($report['rows'])->keyBy('label');

    expect($rows[$this->grant->label()])->toMatchArray(['period_income' => '0.0000', 'period_expenditure' => '9.4322', 'period_balance' => '-9.4322', 'cumulative_income' => '600.1234', 'cumulative_expenditure' => '260.0000', 'cumulative_balance' => '340.1234'])
        ->and($rows['No fund']['cumulative_balance'])->toBe('35.5000')
        ->and($report['totals']['cumulative_balance'])->toBe('775.7467');
});

test('grant utilisation and the donor report are exact, branch scoped and exportable', function () {
    fundFixture($this);
    $this->actingAs($this->accountant);
    $report = app(GrantUtilisationReport::class)->build($this->accountant, ['as_of' => '2026-12-31']);

    expect($report['rows'])->toHaveCount(1)
        ->and(collect($report['rows'][0])->except('fund')->all())->toBe([
            'period' => '01 Jan 2026 to 31 Dec 2026', 'grant_amount' => '1000.0000', 'income' => '600.1234', 'expenditure' => '250.5678',
            'utilisation' => '25.06', 'remaining' => '749.4322', 'in_hand' => '349.5556', 'has_activity' => true,
        ]);

    // An earlier "as of" date leaves out the later expenditure.
    expect(app(GrantUtilisationReport::class)->build($this->accountant, ['as_of' => '2026-01-06'])['rows'][0]['expenditure'])->toBe('0.0000');
    // The consolidated view includes the Karachi expenditure.
    expect(app(GrantUtilisationReport::class)->build($this->admin, ['as_of' => '2026-12-31'])['rows'][0]['expenditure'])->toBe('1249.5678');

    $donor = app(GrantUtilisationReport::class)->donorReport($this->accountant, $this->grant, ['as_of' => '2026-12-31']);
    expect($donor['income_lines'])->toBe([['code' => '432001', 'name' => 'Grant Income', 'amount' => '600.1234']])
        ->and($donor['expense_lines'])->toBe([['code' => '512001', 'name' => 'Patient Medicine', 'amount' => '250.5678']]);

    $this->get(route('fund-reports.grant-utilisation', ['as_of' => '2026-12-31']))->assertOk()->assertSee('25.06%')->assertSee('749.43');
    $this->get(route('fund-reports.donor-report', ['fund' => $this->grant, 'as_of' => '2026-12-31']))->assertOk()->assertSee('Example Trust')->assertSee('Patient Medicine');
    $this->get(route('fund-reports.donor-report', $this->zakat))->assertNotFound();
    $csv = $this->get(route('fund-reports.donor-report.export', ['fund' => $this->grant, 'format' => 'csv', 'as_of' => '2026-12-31']))->assertOk()->streamedContent();
    expect($csv)->toContain('Donor Report')->toContain('749.4322');
});

test('fund reports keep to the user branch access and need their permissions', function () {
    fundFixture($this);
    $this->actingAs($this->accountant);
    $report = app(FundIncomeExpenditureReport::class)->build($this->accountant, ['from' => '2026-01-01', 'to' => '2026-01-31']);
    expect($report['total_expenses']['total'])->toBe('390.5679');

    $this->get(route('fund-reports.income-expenditure', ['branch_id' => $this->karachi->id]))->assertForbidden();
    $this->get(route('fund-reports.balance-summary', ['branch_id' => 'all']))->assertForbidden();

    $cashier = fundUser(SystemRole::Cashier, [$this->lahore]);
    $this->actingAs($cashier)->get(route('fund-reports.income-expenditure'))->assertForbidden();
    $this->get(route('fund-reports.grant-utilisation'))->assertForbidden();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['fund_reports.view']);
    $viewer->branches()->attach($this->lahore);
    $this->actingAs($viewer)->get(route('fund-reports.balance-summary', ['from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk()->assertDontSee('Export CSV');
    $this->get(route('fund-reports.export', ['report' => 'balance-summary', 'format' => 'csv', 'from' => '2026-01-01', 'to' => '2026-01-31']))->assertForbidden();
});

test('year-end closing journals do not move fund-wise income and expenditure', function () {
    fundFixture($this);
    $equity = Account::factory()->create(['code' => '311001', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    fundJournal($this, '2026-12-31', [[$this->donations, '75.5000', '0'], [$equity, '0', '75.5000']], overrides: ['source_type' => 'year_end_closing', 'source_key' => 'year_end_closing:fixture']);

    $report = app(FundIncomeExpenditureReport::class)->build($this->admin, ['branch_id' => (string) $this->lahore->id, 'fiscal_year_id' => $this->year->id]);
    expect($report['total_income']['none'])->toBe('75.5000');
});
