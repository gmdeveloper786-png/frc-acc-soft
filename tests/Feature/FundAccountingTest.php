<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
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
use Illuminate\Http\UploadedFile;
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
    $this->salaries = Account::factory()->create(['code' => '511001', 'name' => 'Salaries', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->medicine = Account::factory()->create(['code' => '512001', 'name' => 'Patient Medicine', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
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

test('permitted charge ledger selector and validator include only expense and eligible capital asset ledgers', function () {
    $bank = Account::factory()->create(['code' => '112101', 'name' => 'Bank Account', 'account_type' => 'asset']);
    $receivable = Account::factory()->create(['code' => '113101', 'name' => 'Fees Receivable', 'account_type' => 'asset']);
    $advance = Account::factory()->create(['code' => '114101', 'name' => 'Staff Advances', 'account_type' => 'asset']);
    $accDep = Account::factory()->create(['code' => '125101', 'name' => 'Acc. Depreciation - Equipment', 'account_type' => 'asset', 'normal_balance' => 'credit']);
    $fund = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT-TEST', 'name' => 'Test Restricted Fund']);

    // UI form selector only exposes eligible expense and capital asset ledgers
    $response = $this->actingAs($this->accountant)->get(route('funds.edit', $fund))->assertOk();
    $chargeLedgerIds = $response->viewData('chargeLedgers')->pluck('id')->all();

    expect($chargeLedgerIds)->toContain($this->medicine->id)
        ->and($chargeLedgerIds)->toContain($this->salaries->id)
        ->and($chargeLedgerIds)->toContain($this->equipment->id)
        ->and($chargeLedgerIds)->not->toContain($this->cashLedger->id)
        ->and($chargeLedgerIds)->not->toContain($bank->id)
        ->and($chargeLedgerIds)->not->toContain($receivable->id)
        ->and($chargeLedgerIds)->not->toContain($advance->id)
        ->and($chargeLedgerIds)->not->toContain($accDep->id);

    // Manipulated store request with cash account is rejected
    $this->post(route('funds.store'), [
        'code' => 'BAD-CASH', 'name' => 'Bad Cash Fund', 'type' => 'restricted', 'category' => 'donor',
        'permitted_account_ids' => [$this->cashLedger->id],
    ])->assertSessionHasErrors(['permitted_account_ids' => 'Permitted ledgers must be active, postable expense or eligible capital asset ledgers. Cash, bank, receivables, and advances cannot be selected.']);

    // Manipulated update request with bank account is rejected
    $this->put(route('funds.update', $fund), [
        'code' => 'ZKT-TEST', 'name' => 'Test Restricted Fund', 'type' => 'restricted', 'category' => 'zakat',
        'permitted_account_ids' => [$bank->id],
    ])->assertSessionHasErrors(['permitted_account_ids' => 'Permitted ledgers must be active, postable expense or eligible capital asset ledgers. Cash, bank, receivables, and advances cannot be selected.']);

    // Manipulated store request with receivable is rejected
    $this->post(route('funds.store'), [
        'code' => 'BAD-RECV', 'name' => 'Bad Recv Fund', 'type' => 'restricted', 'category' => 'donor',
        'permitted_account_ids' => [$receivable->id],
    ])->assertSessionHasErrors('permitted_account_ids');

    // Manipulated store request with advance is rejected
    $this->post(route('funds.store'), [
        'code' => 'BAD-ADV', 'name' => 'Bad Adv Fund', 'type' => 'restricted', 'category' => 'donor',
        'permitted_account_ids' => [$advance->id],
    ])->assertSessionHasErrors('permitted_account_ids');

    // Manipulated store request with accumulated depreciation is rejected
    $this->post(route('funds.store'), [
        'code' => 'BAD-DEP', 'name' => 'Bad Dep Fund', 'type' => 'restricted', 'category' => 'donor',
        'permitted_account_ids' => [$accDep->id],
    ])->assertSessionHasErrors('permitted_account_ids');

    // Valid expense and capital asset ledgers are accepted
    $this->put(route('funds.update', $fund), [
        'code' => 'ZKT-TEST', 'name' => 'Test Restricted Fund', 'type' => 'restricted', 'category' => 'zakat',
        'permitted_account_ids' => [$this->medicine->id, $this->equipment->id],
    ])->assertRedirect(route('funds.index'));

    expect($fund->fresh()->permittedAccounts->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->medicine->id, $this->equipment->id])->sort()->values()->all());
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
    $general = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED]);
    $general2 = Fund::factory()->create(['code' => 'GEN-02', 'name' => 'General Fund 2', 'type' => Fund::TYPE_UNRESTRICTED]);
    $category = DonationCategory::query()->where('name', 'General Donation')->firstOrFail();
    $this->actingAs($this->admin)->put(route('donation-categories.update', $category), ['income_account_id' => $this->donations->id, 'fund_id' => $general->id, 'is_active' => '1'])->assertRedirect();
    expect($category->fresh()->fund_id)->toBe($general->id);

    $service = app(OperationalTransactionService::class);
    $payload = ['type' => 'donation', 'branch_id' => $this->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '250.1234', 'financial_account_id' => $this->cash->id, 'donation_category_id' => $category->id, 'narration' => 'General donation'];
    $inherited = $service->post($service->create($payload, $this->admin), $this->admin);
    $chosen = $service->create([...$payload, 'fund_id' => $general2->id], $this->admin);

    expect($inherited->fund_id)->toBe($general->id)->and($chosen->fund_id)->toBe($general2->id)
        ->and($inherited->journalEntry->lines->pluck('fund_id')->unique()->all())->toBe([$general->id]);

    $plain = $service->create(['type' => 'cash_receipt', 'branch_id' => $this->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '10', 'financial_account_id' => $this->cash->id, 'contra_account_id' => $this->donations->id, 'narration' => 'No fund'], $this->admin);
    expect($service->post($plain, $this->admin)->journalEntry->lines->pluck('fund_id')->unique()->all())->toBe([null]);
});

test('donation category default fund is automatically applied, overrides are preserved, and invalid restricted-fund combinations are blocked', function () {
    $gen = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund']);
    $zkt = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat / Charity Fund']);
    $cap = Fund::factory()->restricted('capital')->create(['code' => 'CAP', 'name' => 'Capital / Building Fund']);
    $grt = Fund::factory()->grant('500000.0000')->create(['code' => 'GRT', 'name' => 'Grant Project Fund']);

    $generalCategory = DonationCategory::query()->where('name', 'General Donation')->firstOrFail();
    $this->actingAs($this->admin)->put(route('donation-categories.update', $generalCategory), [
        'income_account_id' => $this->donations->id,
        'fund_id' => $gen->id,
        'is_active' => '1',
    ])->assertRedirect();

    $zakatCategory = DonationCategory::query()->where('name', 'Zakat')->firstOrFail();
    $this->put(route('donation-categories.update', $zakatCategory), [
        'income_account_id' => $this->donations->id,
        'fund_id' => $zkt->id,
        'is_active' => '1',
    ])->assertRedirect();

    // 1. Initial form load: General Donation is first category, so its configured default fund GEN is pre-selected
    $response = $this->get(route('transactions.create', 'donation'))->assertOk();
    $response->assertSee('data-fund-id="'.$gen->id.'"', false);
    $response->assertSee('<option value="'.$gen->id.'" selected>GEN — General Fund</option>', false);

    // 2. Default fund inheritance on save without fund_id
    $basePayload = [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'amount' => '500.0000',
        'financial_account_id' => $this->cash->id,
        'narration' => 'General donation without explicit fund',
    ];
    $this->post(route('transactions.store'), [
        ...$basePayload,
        'donation_category_id' => $generalCategory->id,
    ])->assertRedirect();

    $tx1 = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($tx1->fund_id)->toBe($gen->id);

    // 3. Legitimate explicit override (General Donation with Capital Fund CAP)
    $this->post(route('transactions.store'), [
        ...$basePayload,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $cap->id,
        'narration' => 'General donation explicitly allocated to CAP',
    ])->assertRedirect();

    $tx2 = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($tx2->fund_id)->toBe($cap->id);

    // 4. Invalid combination: General Donation with ZKT restricted fund is blocked
    $this->post(route('transactions.store'), [
        ...$basePayload,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $zkt->id,
        'narration' => 'Invalid: General donation to Zakat fund',
    ])->assertSessionHasErrors([
        'fund_id' => 'Restricted fund ZKT (Zakat / Charity Fund) can only receive Zakat donations. It cannot be selected for General Donation.',
    ]);

    // 5. Invalid combination: Donation to Grant Project Fund GRT is blocked
    $this->post(route('transactions.store'), [
        ...$basePayload,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $grt->id,
        'narration' => 'Invalid: Donation to Grant fund',
    ])->assertSessionHasErrors([
        'fund_id' => 'Grant project fund GRT is reserved for contractual grants. Donations cannot be allocated to grant funds.',
    ]);

    // 6. Valid Zakat combination: Zakat category with ZKT fund is accepted and posted lines carry ZKT
    $this->post(route('transactions.store'), [
        ...$basePayload,
        'donation_category_id' => $zakatCategory->id,
        'fund_id' => $zkt->id,
        'narration' => 'Valid Zakat donation',
    ])->assertRedirect();

    $zakatTx = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($zakatTx->fund_id)->toBe($zkt->id);

    $posted = app(OperationalTransactionService::class)->post($zakatTx, $this->admin);
    expect($posted->status->value)->toBe('posted')
        ->and($posted->journalEntry->lines->pluck('fund_id')->unique()->all())->toBe([$zkt->id]);

    // 7. Backend service guard at submit and post also blocks manipulated record
    $manipulated = OperationalTransaction::factory()->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $zkt->id,
        'status' => 'draft',
        'created_by' => $this->admin->id,
    ]);

    expect(fn () => app(OperationalTransactionService::class)->post($manipulated, $this->admin))
        ->toThrow(ValidationException::class);
});

test('the transaction form offers an optional fund and stores it', function () {
    $fund = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund']);
    Fund::factory()->create(['code' => 'OLD', 'is_active' => false]);

    $this->actingAs($this->admin)->get(route('transactions.create', 'cash_receipt'))->assertOk()->assertSee('Fund Allocation (optional)')->assertSee('GEN — General Fund')->assertDontSee('OLD —');
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
    return ['type' => $type, 'branch_id' => $test->lahore->id, 'transaction_date' => '2026-01-15', 'amount' => '50.0000', 'financial_account_id' => $type === 'petty_cash' ? fundedPettyCashAccount($test->lahore->id, '1000.0000')->id : $test->cash->id, 'contra_account_id' => $ledger->id, 'fund_id' => $fund?->id, 'narration' => 'Fund-tagged charge'];
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

test('funds list displays blocked status for restricted funds without ledgers and any for unrestricted', function () {
    $general = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general']);
    $zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat Fund']);
    $grant = Fund::factory()->grant('1000.0000')->create(['code' => 'GRT-01', 'name' => 'Clinic Grant']);
    $grant->permittedAccounts()->sync([$this->medicine->id, $this->salaries->id]);

    $this->actingAs($this->admin)->get(route('funds.index'))
        ->assertOk()
        ->assertSee('No permitted ledgers configured — charges blocked')
        ->assertSee('Any (Unrestricted)')
        ->assertDontSee('Leave empty to permit any ledger');

    $this->actingAs($this->admin)->get(route('funds.edit', $zakat))
        ->assertOk()
        ->assertSee('Permitted charge ledgers (restricted funds)')
        ->assertSee('For restricted funds, select the expense and capital asset ledgers that may be charged to this fund')
        ->assertDontSee('Leave empty to permit any ledger')
        ->assertDontSee('once a Director enforces the rule');
});

test('operational transactions and journals enforce deny-by-default for empty restricted funds while general fund is unrestricted', function () {
    $general = Fund::factory()->create(['code' => 'GEN', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general']);
    $zakat = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT']);
    $this->actingAs($this->admin);

    // 1. Restricted fund with empty permitted accounts blocks expense payment at creation
    $blockedMessage = 'Restricted fund ZKT has no permitted ledgers configured, so nothing can be charged to it. Set its permitted ledgers under Funds & Grants first.';
    expect(fn () => app(OperationalTransactionService::class)->create(
        fundPayment($this, 'expense', $this->medicine, $zakat),
        $this->admin
    ))->toThrow(ValidationException::class, $blockedMessage);

    // 2. Unrestricted General Fund allows charging any expense without permitted accounts
    $genExpense = app(OperationalTransactionService::class)->create(
        fundPayment($this, 'expense', $this->medicine, $general),
        $this->admin
    );
    expect($genExpense->exists)->toBeTrue();

    // 3. Restricted fund with permitted accounts allows configured ledger and rejects unconfigured ledger
    $zakat->permittedAccounts()->sync([$this->medicine->id]);
    $allowedExpense = app(OperationalTransactionService::class)->create(
        fundPayment($this, 'expense', $this->medicine, $zakat),
        $this->admin
    );
    expect($allowedExpense->exists)->toBeTrue();

    expect(fn () => app(OperationalTransactionService::class)->create(
        fundPayment($this, 'expense', $this->salaries, $zakat),
        $this->admin
    ))->toThrow(ValidationException::class, 'Ledger 511001 Salaries is not a permitted charge for restricted fund ZKT.');

    // Advance to approved state
    app(OperationalTransactionService::class)->submit($allowedExpense, $this->admin);
    app(OperationalTransactionService::class)->approve($allowedExpense->fresh(), $this->checker);

    // 4. Changing permitted accounts after approval blocks posting at validation time
    $zakat->permittedAccounts()->sync([]);
    expect(fn () => app(OperationalTransactionService::class)->post($allowedExpense->fresh(), $this->poster))
        ->toThrow(ValidationException::class, $blockedMessage);
});

test('Policy 1 — Mandatory ZKT: Zakat donations must use ZKT fund and reject create or post without ZKT', function () {
    $zakatCategory = DonationCategory::query()->where('slug', 'zakat')->firstOrFail();
    $zakatCategory->update(['income_account_id' => $this->donations->id]);

    $zktFund = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat / Charity Fund']);
    $genFund = Fund::factory()->create(['code' => 'GEN', 'type' => Fund::TYPE_UNRESTRICTED]);

    $service = app(OperationalTransactionService::class);

    // 1. HTTP create with wrong fund (GEN) is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Zakat Donor',
        'amount' => '2000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $zakatCategory->id,
        'fund_id' => $genFund->id,
        'narration' => 'Zakat donation with invalid GEN fund',
    ])->assertSessionHasErrors(['fund_id' => 'Zakat donations must be allocated to the ZKT (Zakat / Charity) fund.']);

    // 2. Service create with ZKT fund succeeds
    $donation = $service->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Zakat Donor',
        'amount' => '2000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $zakatCategory->id,
        'fund_id' => $zktFund->id,
        'narration' => 'Zakat donation with valid ZKT fund',
    ], $this->accountant);

    expect($donation->status->value)->toBe('draft')
        ->and($donation->fund_id)->toBe($zktFund->id);

    // 3. Posting with ZKT fund succeeds
    $posted = $service->post($donation, $this->accountant);
    expect($posted->status->value)->toBe('posted')
        ->and($posted->journalEntry->voucherType->code)->toBe('DR');

    // 4. Posting a Zakat draft mutated to blank or non-ZKT fund is rejected
    $invalidDraft = $service->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Zakat Donor 2',
        'amount' => '1000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $zakatCategory->id,
        'fund_id' => $zktFund->id,
        'narration' => 'Zakat test 2',
    ], $this->accountant);

    // Simulate bypass attempt on saved draft
    OperationalTransaction::query()->where('id', $invalidDraft->id)->update(['fund_id' => null]);
    expect(fn () => $service->post($invalidDraft->fresh(), $this->accountant))
        ->toThrow(ValidationException::class, 'Zakat donations must be allocated to the ZKT (Zakat / Charity) fund before posting.');
});

test('Policy 2 — Project-specific funds: New FRC Project Donation requires active child fund under DNR and rejects parent DNR', function () {
    $projectCategory = DonationCategory::query()->where('slug', 'new-frc-project-donation')->firstOrFail();
    $projectCategory->update(['income_account_id' => $this->donations->id]);

    $dnrParent = Fund::factory()->restricted('donor')->create(['code' => 'DNR', 'name' => 'Donor-Restricted Fund', 'parent_id' => null]);
    $dnrChild = Fund::factory()->restricted('donor')->create(['code' => 'DNR-CP1', 'name' => 'Child Protection Project', 'parent_id' => $dnrParent->id, 'is_active' => true]);
    $inactiveChild = Fund::factory()->restricted('donor')->create(['code' => 'DNR-OLD', 'name' => 'Inactive Child Project', 'parent_id' => $dnrParent->id, 'is_active' => false]);

    $service = app(OperationalTransactionService::class);

    // 1. HTTP create without fund is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Project Donor',
        'amount' => '3000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => '',
        'narration' => 'Project donation without fund',
    ])->assertSessionHasErrors(['fund_id' => 'New FRC Project Donations must be allocated to an active project child fund under DNR.']);

    // 2. HTTP create with parent DNR alone is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Project Donor',
        'amount' => '3000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $dnrParent->id,
        'narration' => 'Project donation with parent DNR',
    ])->assertSessionHasErrors(['fund_id' => 'New FRC Project Donations must be allocated to an active project child fund under DNR. Generic parent DNR cannot be used.']);

    // 3. HTTP create with inactive child fund is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Project Donor',
        'amount' => '3000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $inactiveChild->id,
        'narration' => 'Project donation with inactive child',
    ])->assertSessionHasErrors(['fund_id' => 'The selected project fund is not active.']);

    // 4. HTTP create with valid active child fund under DNR succeeds
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Project Donor',
        'amount' => '3000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $dnrChild->id,
        'narration' => 'Project donation with valid child fund',
    ])->assertSessionHasNoErrors();

    // 5. Existing historical draft with parent DNR (simulating Donation #31) cannot post
    $historicalDraft = OperationalTransaction::query()->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Historical Donor',
        'amount' => '3000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $dnrParent->id, // parent DNR!
        'narration' => 'Historical draft with parent DNR',
        'status' => OperationalTransactionStatus::Draft,
        'created_by' => $this->accountant->id,
    ]);

    expect(fn () => $service->post($historicalDraft, $this->accountant))
        ->toThrow(ValidationException::class, 'New FRC Project Donations must be allocated to an active project child fund under DNR before posting. Generic parent DNR cannot be used.');
});

test('Policy 5 — Fund compatibility: General Donation rejects ZKT and GRT, requires donor restriction evidence for DNR funds, and allows GEN', function () {
    $generalCategory = DonationCategory::query()->where('slug', 'general-donation')->firstOrFail();
    $generalCategory->update(['income_account_id' => $this->donations->id]);

    $genFund = Fund::factory()->create(['code' => 'GEN', 'type' => Fund::TYPE_UNRESTRICTED]);
    $zktFund = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT']);
    $grtFund = Fund::factory()->grant('5000')->create(['code' => 'GRT-01']);
    $dnrParent = Fund::factory()->restricted('donor')->create(['code' => 'DNR']);
    $dnrChild = Fund::factory()->restricted('donor')->create(['code' => 'DNR-CP1', 'parent_id' => $dnrParent->id]);

    $service = app(OperationalTransactionService::class);

    // 1. General Donation -> ZKT is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'General Donor',
        'amount' => '5000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $zktFund->id,
        'narration' => 'General donation to ZKT',
    ])->assertSessionHasErrors(['fund_id']);

    // 2. General Donation -> GRT is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'General Donor',
        'amount' => '5000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $grtFund->id,
        'narration' => 'General donation to GRT',
    ])->assertSessionHasErrors(['fund_id']);

    // 3. General Donation -> DNR child fund WITHOUT attachment is rejected
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'General Donor',
        'amount' => '5000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $dnrChild->id,
        'narration' => 'General donation to DNR child without attachment',
    ])->assertSessionHasErrors(['attachment' => 'Allocating a General Donation to a donor-restricted fund requires an attached donor-written restriction document.']);

    // 4. General Donation -> DNR child fund WITH attachment is allowed
    $file = UploadedFile::fake()->create('donor_restriction.pdf', 100, 'application/pdf');
    $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'General Donor',
        'amount' => '5000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $dnrChild->id,
        'narration' => 'General donation to DNR child with attachment',
        'attachment' => $file,
    ])->assertSessionHasNoErrors();

    // 5. General Donation -> GEN is allowed without attachment
    $genDonation = $service->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Unrestricted Donor',
        'amount' => '5000.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $genFund->id,
        'narration' => 'Unrestricted donation to GEN',
    ], $this->accountant);

    $posted = $service->post($genDonation, $this->accountant);
    expect($posted->status->value)->toBe('posted')
        ->and($posted->journalEntry->voucherType->code)->toBe('DR');
});

test('Policy 3 — Restricted expense ledger: GL 52201 synthetic UAT allow-case permitted when configured on dedicated restricted fund, while other ledgers are denied', function () {
    $gl52201 = Account::factory()->create([
        'code' => '52201',
        'name' => 'Therapy Materials & Consumables',
        'account_type' => 'expense',
        'direct_payment_eligible' => true,
        'financial_statement' => 'income_expenditure',
    ]);

    $dnrParent = Fund::factory()->restricted('donor')->create(['code' => 'DNR', 'name' => 'Donor-Restricted Fund']);
    $uatFund = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-UAT',
        'name' => 'Dedicated UAT Project Fund',
        'parent_id' => $dnrParent->id,
        'is_active' => true,
    ]);

    $otherRestrictedFund = Fund::factory()->restricted('capital')->create(['code' => 'CAP-01', 'name' => 'Building Fund']);

    $service = app(OperationalTransactionService::class);

    // 1. Deny-by-default: GL 52201 cannot be charged before permitted accounts are configured
    expect(fn () => $service->create(
        fundPayment($this, 'expense', $gl52201, $uatFund),
        $this->admin
    ))->toThrow(ValidationException::class, 'Restricted fund DNR-UAT has no permitted ledgers configured');

    // 2. Configure temporary UAT permission: permit only GL 52201 on dedicated UAT fund
    $uatFund->permittedAccounts()->sync([$gl52201->id]);

    // Allowed charge: GL 52201 is permitted on DNR-UAT
    $allowedExpense = $service->create(
        fundPayment($this, 'expense', $gl52201, $uatFund),
        $this->admin
    );
    expect($allowedExpense->exists)->toBeTrue();

    // 3. Denied charge: Unpermitted expense ledger (salaries) on DNR-UAT remains denied
    expect(fn () => $service->create(
        fundPayment($this, 'expense', $this->salaries, $uatFund),
        $this->admin
    ))->toThrow(ValidationException::class, 'Ledger 511001 Salaries is not a permitted charge for restricted fund DNR-UAT.');

    // 4. Denied charge: GL 52201 on another restricted fund (CAP-01) where it is not configured is denied
    expect(fn () => $service->create(
        fundPayment($this, 'expense', $gl52201, $otherRestrictedFund),
        $this->admin
    ))->toThrow(ValidationException::class, 'Restricted fund CAP-01 has no permitted ledgers configured');
});

test('Step 7B-10A: Contextual attachment guidance on create donation form adapts to category and fund', function () {
    $genFund = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED]);
    $zktFund = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat / Charity Fund']);
    $dnrParent = Fund::factory()->restricted('donor')->create(['code' => 'DNR', 'name' => 'Donor-Restricted Fund']);
    $dnrChild = Fund::factory()->restricted('donor')->create(['code' => 'DNR-CP1', 'name' => 'Child Protection Project', 'parent_id' => $dnrParent->id]);

    $generalCategory = DonationCategory::query()->where('slug', 'general-donation')->firstOrFail();
    $generalCategory->update(['income_account_id' => $this->donations->id, 'fund_id' => $genFund->id]);

    $zakatCategory = DonationCategory::query()->where('slug', 'zakat')->firstOrFail();
    $zakatCategory->update(['income_account_id' => $this->donations->id, 'fund_id' => $zktFund->id]);

    $projectCategory = DonationCategory::query()->where('slug', 'new-frc-project-donation')->firstOrFail();
    $projectCategory->update(['income_account_id' => $this->donations->id, 'fund_id' => null]);

    // 1. Initial form load (General Donation with GEN): shows optional general attachment guidance
    $res1 = $this->actingAs($this->admin)->get(route('transactions.create', 'donation'))->assertOk();
    $res1->assertSee('<div class="form-text" id="transaction-attachment-help">Optional: Attach donor acknowledgment, deposit slip, or payment confirmation if available.</div>', false);

    // 2. Loading with Zakat (e.g. redisplay or old input): shows Zakat specific guidance
    $res2 = $this->actingAs($this->admin)->withSession(['_old_input' => ['donation_category_id' => $zakatCategory->id]])
        ->get(route('transactions.create', 'donation'))->assertOk();
    $res2->assertSee('<div class="form-text" id="transaction-attachment-help">Optional: Attach bank deposit slip, cheque image, or donor Zakat declaration if available.</div>', false);

    // 3. Loading with New FRC Project Donation: shows project confirmation guidance
    $res3 = $this->actingAs($this->admin)->withSession(['_old_input' => ['donation_category_id' => $projectCategory->id]])
        ->get(route('transactions.create', 'donation'))->assertOk();
    $res3->assertSee('<div class="form-text" id="transaction-attachment-help">Donor project confirmation required before processing. Attach donor project pledge/agreement, or confirm project attribution via child fund selection.</div>', false);

    // 4. Loading with General Donation and DNR child fund: shows mandatory restriction letter guidance and required star
    $res4 = $this->actingAs($this->admin)->withSession(['_old_input' => [
        'donation_category_id' => $generalCategory->id,
        'fund_id' => $dnrChild->id,
    ]])->get(route('transactions.create', 'donation'))->assertOk();
    $res4->assertSee('<div class="form-text" id="transaction-attachment-help">Mandatory: Attach donor-written restriction letter when allocating General Donations to a restricted project fund.</div>', false)
        ->assertDontSee('id="transaction-attachment-required" class="text-danger ms-1 d-none"', false);

    // 5. Verification that Zakat and Project donations do not require mandatory attachment
    $service = app(OperationalTransactionService::class);
    $zakatDonation = $service->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Zakat Donor',
        'amount' => '1500.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $zakatCategory->id,
        'fund_id' => $zktFund->id,
        'narration' => 'Zakat without file attachment',
    ], $this->admin);
    expect($zakatDonation->exists)->toBeTrue();

    $projectDonation = $service->create([
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Project Donor',
        'amount' => '2500.0000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $dnrChild->id,
        'narration' => 'Project donation without file attachment',
    ], $this->admin);
    expect($projectDonation->exists)->toBeTrue();
});

test('Step 7B-12B: Project Donation fund dropdown filtering, inactive funds and backend rejection', function (): void {
    $genFund = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED]);
    $zktFund = Fund::factory()->restricted('zakat')->create(['code' => 'ZKT', 'name' => 'Zakat / Charity Fund']);
    $dnrParent = Fund::factory()->restricted('donor')->create(['code' => 'DNR', 'name' => 'Donor-Restricted Fund']);
    $activeChild = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-PROJ-ACT',
        'name' => 'Active Project Child',
        'parent_id' => $dnrParent->id,
        'is_active' => true,
    ]);

    $projectCategory = DonationCategory::query()->where('slug', 'new-frc-project-donation')->firstOrFail();
    $projectCategory->update(['income_account_id' => $this->donations->id, 'fund_id' => null]);

    $generalCategory = DonationCategory::query()->where('slug', 'general-donation')->firstOrFail();
    $generalCategory->update(['income_account_id' => $this->donations->id, 'fund_id' => $genFund->id]);

    // 1. When category is New FRC Project Donation:
    // Only active project child funds under DNR are shown; parent DNR, GEN, ZKT and others are hidden & disabled.
    $res = $this->actingAs($this->admin)
        ->withSession(['_old_input' => ['donation_category_id' => $projectCategory->id]])
        ->get(route('transactions.create', 'donation'))
        ->assertOk();

    // Active child fund under DNR is NOT hidden/disabled
    $res->assertSee('<option value="'.$activeChild->id.'">'.$activeChild->label().' (restricted)</option>', false);

    // Parent DNR is hidden and disabled
    $res->assertSee('<option value="'.$dnrParent->id.'" hidden disabled style="display: none;">'.$dnrParent->label().' (restricted)</option>', false);

    // GEN is hidden and disabled
    $res->assertSee('<option value="'.$genFund->id.'" hidden disabled style="display: none;">'.$genFund->label().'</option>', false);

    // ZKT is hidden and disabled
    $res->assertSee('<option value="'.$zktFund->id.'" hidden disabled style="display: none;">'.$zktFund->label().' (restricted)</option>', false);

    // Default option placeholder when active child funds exist
    $res->assertSee('Select active project child fund under DNR');

    // 2. When NO active DNR child fund exists: clear guidance to contact Fund Manager
    $activeChild->update(['is_active' => false]);
    $resNoChild = $this->actingAs($this->admin)
        ->withSession(['_old_input' => ['donation_category_id' => $projectCategory->id]])
        ->get(route('transactions.create', 'donation'))
        ->assertOk();
    $resNoChild->assertSee('No active project funds available — contact an authorized Fund Manager');
    $resNoChild->assertSee('No active project funds available under DNR. Please contact an authorized Fund Manager to set up an active project fund before proceeding.');
    $activeChild->update(['is_active' => true]);

    // 3. Validation redisplay preserves valid selected child fund
    $resRedisplayValid = $this->actingAs($this->admin)
        ->withSession(['_old_input' => [
            'donation_category_id' => $projectCategory->id,
            'fund_id' => $activeChild->id,
        ]])
        ->get(route('transactions.create', 'donation'))
        ->assertOk();
    $resRedisplayValid->assertSee('<option value="'.$activeChild->id.'" selected>'.$activeChild->label().' (restricted)</option>', false);

    // 4. Validation redisplay does NOT select manipulated parent DNR
    $resRedisplayInvalid = $this->actingAs($this->admin)
        ->withSession(['_old_input' => [
            'donation_category_id' => $projectCategory->id,
            'fund_id' => $dnrParent->id,
        ]])
        ->get(route('transactions.create', 'donation'))
        ->assertOk();
    $resRedisplayInvalid->assertDontSee('<option value="'.$dnrParent->id.'" hidden disabled style="display: none;" selected>', false);
    $resRedisplayInvalid->assertDontSee('<option value="'.$dnrParent->id.'" selected>', false);

    // 5. Backend validation rejection of parent DNR for Project Donation
    $resPostParent = $this->actingAs($this->admin)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Manipulated Donor',
        'amount' => '1000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $dnrParent->id,
        'narration' => 'Trying to use generic parent DNR',
    ]);
    $resPostParent->assertSessionHasErrors(['fund_id']);

    // 6. Backend validation rejection of GEN for Project Donation
    $resPostGen = $this->actingAs($this->admin)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Manipulated Donor',
        'amount' => '1000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $genFund->id,
        'narration' => 'Trying to use GEN for project donation',
    ]);
    $resPostGen->assertSessionHasErrors(['fund_id']);

    // 7. Backend validation rejection of inactive child fund
    $inactiveChild = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-PROJ-INACT',
        'name' => 'Inactive Project Child',
        'parent_id' => $dnrParent->id,
        'is_active' => false,
    ]);

    $resPostInactive = $this->actingAs($this->admin)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Manipulated Donor',
        'amount' => '1000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $inactiveChild->id,
        'narration' => 'Trying to use inactive child fund',
    ]);
    $resPostInactive->assertSessionHasErrors(['fund_id']);

    // 8. Successful creation with valid active child fund
    $resPostValid = $this->actingAs($this->admin)->post(route('transactions.store'), [
        'type' => 'donation',
        'branch_id' => $this->lahore->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Valid Project Donor',
        'amount' => '2000',
        'financial_account_id' => $this->cash->id,
        'donation_category_id' => $projectCategory->id,
        'fund_id' => $activeChild->id,
        'narration' => 'Valid project child fund donation',
    ]);
    $resPostValid->assertSessionHasNoErrors();
    $resPostValid->assertRedirect();
});
