<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\GeneralLedgerService;
use App\Services\ManualJournalGuard;
use App\Services\OperationalTransactionService;
use App\Services\Reports\CashFlow\CashFlowStatementService;
use App\Services\Reports\CashFlow\ReceiptsPaymentsService;
use App\Services\Reports\Statements\BalanceSheetStatement;
use App\Services\Reports\Statements\BranchIncomeExpenditureStatement;
use App\Services\TrialBalanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

// One organisational cash ledger backs one cash account in each branch; branch balances come from the journal's branch.

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->branchA = Branch::factory()->create(['name' => 'Shahrah-e-Faisal', 'status' => 'publish']);
    $this->branchB = Branch::factory()->create(['name' => 'Malir Town', 'status' => 'publish']);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->cashLedger = Account::factory()->create(['code' => '11102', 'name' => 'Cash in Hand - Rehab Centre', 'financial_account_type' => 'cash']);
    $this->bankLedger = Account::factory()->create(['code' => '11201', 'name' => 'Bank - Main Operating Account', 'financial_account_type' => 'bank']);
    $this->income = Account::factory()->create(['code' => '42102', 'name' => 'Therapy Session Fees', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->expense = Account::factory()->create(['code' => '53101', 'name' => 'Rent', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->cashA = FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Cash - Shahrah-e-Faisal', 'branch_id' => $this->branchA, 'account_id' => $this->cashLedger]);
    $this->cashB = FinancialAccount::factory()->create(['type' => 'cash', 'name' => 'Cash - Malir Town', 'branch_id' => $this->branchB, 'account_id' => $this->cashLedger]);
    $this->bankA = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Bank - Shahrah-e-Faisal', 'branch_id' => $this->branchA, 'account_id' => $this->bankLedger]);
    $this->bankB = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Bank - Malir Town', 'branch_id' => $this->branchB, 'account_id' => $this->bankLedger]);
});

function branchCashUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function branchCashForm(object $test, array $overrides = []): array
{
    return ['type' => 'cash', 'name' => 'Cash box', 'account_id' => $test->cashLedger->id, 'branch_id' => $test->branchA->id, 'currency' => 'PKR', ...$overrides];
}

/** Posts a cash receipt through the normal receipt workflow. */
function branchCashReceipt(object $test, FinancialAccount $account, string $amount): JournalEntry
{
    $service = app(OperationalTransactionService::class);
    $transaction = $service->create([
        'type' => OperationalTransactionType::CashReceipt->value, 'branch_id' => $account->branch_id, 'transaction_date' => '2026-01-15', 'party_name' => 'Client',
        'amount' => $amount, 'financial_account_id' => $account->id, 'contra_account_id' => $test->income->id, 'narration' => 'Fee received',
    ], $test->admin);

    return $service->post($transaction, $test->admin)->journalEntry;
}

/** Ledger balance (debit − credit) from the Trial Balance for the given branches. */
function branchCashTrialBalance(object $test, array $branchIds): array
{
    $report = app(TrialBalanceService::class)->report($test->year->id, '2026-12-31', $branchIds);
    $row = $report['rows']->first(fn (array $row): bool => $row['account']->id === $test->cashLedger->id);

    return [$row['debit'] ?? '0.0000', $report['balanced']];
}

test('two branches can link their cash accounts to the same organisational ledger', function () {
    FinancialAccount::query()->whereKey($this->cashB->id)->delete();

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), branchCashForm($this, ['name' => 'Cash - Malir Town', 'branch_id' => $this->branchB->id]))
        ->assertRedirect(route('financial-accounts.index'))->assertSessionHasNoErrors();

    expect(FinancialAccount::query()->where('account_id', $this->cashLedger->id)->pluck('branch_id')->sort()->values()->all())
        ->toBe([$this->branchA->id, $this->branchB->id]);
});

test('a branch cannot link the same ledger to a second cash account', function () {
    $this->actingAs($this->admin)->from(route('financial-accounts.create'))
        ->post(route('financial-accounts.store'), branchCashForm($this, ['name' => 'Second cash box']))
        ->assertSessionHasErrors(['account_id' => 'This branch already has a cash or bank account on this ledger. Each branch can link a ledger once, so its balance stays reconcilable.']);

    expect(FinancialAccount::query()->where('account_id', $this->cashLedger->id)->where('branch_id', $this->branchA->id)->count())->toBe(1);
});

test('the database itself rejects a second account on one ledger in one branch', function () {
    FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branchA, 'account_id' => $this->cashLedger]);
})->throws(QueryException::class);

test('cash and bank accounts cannot share a ledger', function () {
    $branchC = Branch::factory()->create(['status' => 'publish']);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), branchCashForm($this, ['type' => 'bank', 'bank_name' => 'Synthetic Bank', 'branch_id' => $branchC->id]))
        ->assertSessionHasErrors(['account_id' => 'This ledger is already used by cash accounts. Cash and bank accounts cannot share a ledger.']);
});

test('cash receipt form marks active accounts with their branch and only submits a matching account', function () {
    $this->actingAs($this->admin)->get(route('transactions.create', 'cash_receipt'))
        ->assertOk()
        ->assertSee('id="transaction-branch"', false)
        ->assertSee('id="transaction-financial-account"', false)
        ->assertSee('data-branch-id="'.$this->branchA->id.'"', false)
        ->assertSee('data-branch-id="'.$this->branchB->id.'"', false)
        ->assertSee('data-account-type="cash"', false)
        ->assertSee('data-account-type="bank"', false)
        ->assertSee('const requiredAccountType = "cash"', false)
        ->assertSee('refreshFinancialAccounts', false);

    $payload = [
        'type' => OperationalTransactionType::CashReceipt->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Branch-matched cash receipt',
    ];

    $this->actingAs($this->admin)->post(route('transactions.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('operational_transactions', [
        'branch_id' => $this->branchA->id,
        'financial_account_id' => $this->cashA->id,
        'narration' => 'Branch-matched cash receipt',
    ]);
});

test('bank receipt form filters accounts by branch and bank type', function () {
    $this->actingAs($this->admin)->get(route('transactions.create', 'bank_receipt'))
        ->assertOk()
        ->assertSee('const requiredAccountType = "bank"', false)
        ->assertSee('option.dataset.accountType === requiredAccountType', false);

    $payload = [
        'type' => OperationalTransactionType::BankReceipt->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->bankA->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Branch-matched bank receipt',
    ];

    $this->actingAs($this->admin)->post(route('transactions.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('operational_transactions', [
        'branch_id' => $this->branchA->id,
        'financial_account_id' => $this->bankA->id,
        'narration' => 'Branch-matched bank receipt',
    ]);
});

test('cash and bank receipts reject forged incompatible account types even for an administrator', function () {
    $cashWithBank = [
        'type' => OperationalTransactionType::CashReceipt->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->bankA->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Forged bank for cash receipt',
    ];
    $bankWithCash = [...$cashWithBank,
        'type' => OperationalTransactionType::BankReceipt->value,
        'financial_account_id' => $this->cashA->id,
        'narration' => 'Forged cash for bank receipt',
    ];

    $this->actingAs($this->admin)->post(route('transactions.store'), $cashWithBank)
        ->assertSessionHasErrors(['financial_account_id' => 'A cash account is required.']);
    $this->actingAs($this->admin)->post(route('transactions.store'), $bankWithCash)
        ->assertSessionHasErrors(['financial_account_id' => 'A bank account is required.']);

    $this->assertDatabaseMissing('operational_transactions', ['narration' => 'Forged bank for cash receipt']);
    $this->assertDatabaseMissing('operational_transactions', ['narration' => 'Forged cash for bank receipt']);
});

test('bank receipt posting refuses an existing cash account association', function () {
    $transaction = OperationalTransaction::query()->create([
        'type' => OperationalTransactionType::BankReceipt,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Invalid bank receipt fixture',
        'created_by' => $this->admin->id,
    ]);

    expect(fn () => app(OperationalTransactionService::class)->post($transaction, $this->admin))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('journal_entries', ['source_id' => (string) $transaction->id]);
});

test('cash receipt rejects a forged cross-branch account selection even for an administrator', function () {
    $payload = [
        'type' => OperationalTransactionType::CashReceipt->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->cashB->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Forged cross-branch cash receipt',
    ];

    $this->actingAs($this->admin)->from(route('transactions.create', 'cash_receipt'))
        ->post(route('transactions.store'), $payload)
        ->assertRedirect(route('transactions.create', 'cash_receipt'))
        ->assertSessionHasErrors(['financial_account_id' => 'The financial account must be active and belong to the selected branch.']);

    $this->assertDatabaseMissing('operational_transactions', ['narration' => 'Forged cross-branch cash receipt']);
});

test('cash receipt posting refuses an existing cross-branch account association', function () {
    $transaction = OperationalTransaction::query()->create([
        'type' => OperationalTransactionType::CashReceipt,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'amount' => '100.0000',
        'financial_account_id' => $this->cashB->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Invalid persisted fixture',
        'created_by' => $this->admin->id,
    ]);

    expect(fn () => app(OperationalTransactionService::class)->post($transaction, $this->admin))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('journal_entries', ['source_id' => (string) $transaction->id]);
});

test('operational reversal defaults to the open period containing its reversal date', function () {
    $receipt = branchCashReceipt($this, $this->cashA, '1000.0000');
    $january = AccountingPeriod::query()->firstOrFail();
    $january->update(['status' => AccountingPeriodStatus::Closed]);
    $october = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year,
        'name' => 'October 2026',
        'period_number' => 10,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->travelTo('2026-10-20');

    $this->actingAs($this->admin)->get(route('transactions.show', OperationalTransaction::query()->where('journal_entry_id', $receipt->id)->firstOrFail()))
        ->assertOk()
        ->assertSee('value="'.$october->id.'" data-start-date="2026-10-01" data-end-date="2026-10-31" selected', false)
        ->assertSee('id="reversal-date" type="date" name="transaction_date" value="2026-10-20"', false)
        ->assertDontSee('<option value="'.$january->id.'" data-start-date="2026-01-01" data-end-date="2026-01-31" selected', false);

    $this->travelBack();
});

test('receipts on a shared ledger keep exact balances per branch and per cash account', function () {
    $journalA = branchCashReceipt($this, $this->cashA, '1000.0000');
    $journalB = branchCashReceipt($this, $this->cashB, '250.0000');

    expect($journalA->branch_id)->toBe($this->branchA->id)
        ->and($journalB->branch_id)->toBe($this->branchB->id)
        ->and($journalA->lines->firstWhere('account_id', $this->cashLedger->id)->debit)->toBe('1000.0000')
        ->and($journalB->lines->firstWhere('account_id', $this->cashLedger->id)->debit)->toBe('250.0000');

    $this->actingAs($this->admin)->get(route('financial-accounts.index'))->assertOk()
        ->assertSeeInOrder(['Cash - Malir Town', '250.00'])
        ->assertSeeInOrder(['Cash - Shahrah-e-Faisal', '1,000.00']);

    expect(branchCashTrialBalance($this, [$this->branchA->id]))->toBe(['1000.0000', true])
        ->and(branchCashTrialBalance($this, [$this->branchB->id]))->toBe(['250.0000', true])
        ->and(branchCashTrialBalance($this, [$this->branchA->id, $this->branchB->id]))->toBe(['1250.0000', true]);
});

test('the cash book splits a shared ledger by branch and filters to one cash account', function () {
    $journalA = branchCashReceipt($this, $this->cashA, '1000.0000');
    $journalB = branchCashReceipt($this, $this->cashB, '250.0000');

    $this->actingAs($this->admin)->get(route('reports.books', 'cash'))->assertOk()
        ->assertSee($journalA->voucher_number)->assertSee($journalB->voucher_number)
        ->assertSee('Cash - Shahrah-e-Faisal')->assertSee('Cash - Malir Town');

    $this->actingAs($this->admin)->get(route('reports.books', ['kind' => 'cash', 'financial_account_id' => $this->cashB->id]))->assertOk()
        ->assertSee($journalB->voucher_number)->assertDontSee($journalA->voucher_number);

    $export = $this->actingAs($this->admin)->get(route('reports.books.export', ['kind' => 'cash', 'format' => 'csv', 'financial_account_id' => $this->cashA->id]))->assertOk();
    expect($export->streamedContent())->toContain('Cash - Shahrah-e-Faisal')->toContain('1000.0000')->not->toContain($journalB->voucher_number);
});

test('a branch user sees only its own branch on a shared ledger and cannot open another branch account', function () {
    $journalA = branchCashReceipt($this, $this->cashA, '1000.0000');
    $journalB = branchCashReceipt($this, $this->cashB, '250.0000');
    $accountantA = branchCashUser(SystemRole::Accountant, $this->branchA);

    $this->actingAs($accountantA)->get(route('reports.books', 'cash'))->assertOk()
        ->assertSee($journalA->voucher_number)->assertDontSee($journalB->voucher_number);
    $this->actingAs($accountantA)->get(route('reports.books', ['kind' => 'cash', 'financial_account_id' => $this->cashB->id]))->assertNotFound();
    $this->actingAs($accountantA)->get(route('financial-accounts.edit', $this->cashB))->assertForbidden();
    $this->actingAs($accountantA)->get(route('financial-accounts.index'))->assertOk()
        ->assertSee('Cash - Shahrah-e-Faisal')->assertDontSee('Cash - Malir Town');
});

test('receipts and payments names a shared ledger after the account only within one branch', function () {
    branchCashReceipt($this, $this->cashA, '1000.0000');
    branchCashReceipt($this, $this->cashB, '250.0000');
    $service = app(ReceiptsPaymentsService::class);

    $branchOnly = $service->statement($this->admin, $this->branchA, '2026-01-01', '2026-01-31');
    $consolidated = $service->statement($this->admin, null, '2026-01-01', '2026-01-31');

    expect($branchOnly['cash_accounts'][0]['name'])->toBe('Cash - Shahrah-e-Faisal')
        ->and($branchOnly['cash_accounts'][0]['closing'])->toBe('1000.0000')
        ->and($consolidated['cash_accounts'][0]['name'])->toBe('Cash in Hand - Rehab Centre')
        ->and($consolidated['cash_accounts'][0]['type'])->toBe('cash')
        ->and($consolidated['cash_accounts'][0]['closing'])->toBe('1250.0000');
});

test('an account is frozen only by postings in its own branch', function () {
    branchCashReceipt($this, $this->cashA, '1000.0000');
    $otherLedger = Account::factory()->create(['code' => '11104', 'financial_account_type' => 'cash']);

    $this->actingAs($this->admin)->put(route('financial-accounts.update', $this->cashA), branchCashForm($this, ['name' => 'Cash - Shahrah-e-Faisal', 'account_id' => $otherLedger->id]))
        ->assertSessionHasErrors('account_id');
    expect($this->cashA->fresh()->account_id)->toBe($this->cashLedger->id);

    $this->actingAs($this->admin)->put(route('financial-accounts.update', $this->cashB), branchCashForm($this, ['name' => 'Cash - Malir Town', 'branch_id' => $this->branchB->id, 'account_id' => $otherLedger->id]))
        ->assertSessionHasNoErrors();
    expect($this->cashB->fresh()->account_id)->toBe($otherLedger->id);
});

test('a manual journal still cannot credit the shared cash ledger', function () {
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20', 'fiscal_year_id' => $this->year->id,
        'accounting_period_id' => AccountingPeriod::query()->value('id'), 'branch_id' => $this->branchB->id, 'narration' => 'Attempted cash withdrawal', 'status' => JournalStatus::Draft, 'created_by' => $this->admin->id,
    ]);
    $journal->lines()->create(['line_number' => 1, 'account_id' => $this->expense->id, 'debit' => '100.0000', 'credit' => '0.0000']);
    $journal->lines()->create(['line_number' => 2, 'account_id' => $this->cashLedger->id, 'debit' => '0.0000', 'credit' => '100.0000']);

    expect(fn () => app(ManualJournalGuard::class)->assertAllowed($journal))->toThrow(ValidationException::class);
});

test('every report counts a shared cash ledger once per branch without double counting', function () {
    branchCashReceipt($this, $this->cashA, '1000.0000');
    branchCashReceipt($this, $this->cashB, '250.0000');
    $both = [$this->branchA->id, $this->branchB->id];

    $ledger = app(GeneralLedgerService::class);
    expect($ledger->report($this->cashLedger, [$this->branchA->id], '2026-01-01', '2026-01-31', null)['closing_balance'])->toBe('1000.0000')
        ->and($ledger->report($this->cashLedger, [$this->branchB->id], '2026-01-01', '2026-01-31', null)['closing_balance'])->toBe('250.0000')
        ->and($ledger->report($this->cashLedger, $both, '2026-01-01', '2026-01-31', null)['closing_balance'])->toBe('1250.0000')
        ->and($ledger->report($this->cashLedger, $both, '2026-01-01', '2026-01-31', null)['rows']->total())->toBe(2);

    $cashFlow = app(CashFlowStatementService::class);
    $flowA = $cashFlow->statement($this->admin, $this->branchA, '2026-01-01', '2026-01-31');
    $flowAll = $cashFlow->statement($this->admin, null, '2026-01-01', '2026-01-31');
    expect($flowA['closing'])->toBe('1000.0000')
        ->and($flowAll['closing'])->toBe('1250.0000')
        ->and($flowAll['opening'])->toBe('0.0000');

    $receipts = app(ReceiptsPaymentsService::class)->statement($this->admin, null, '2026-01-01', '2026-01-31');
    expect($receipts['total_receipts'])->toBe('1250.0000')
        ->and($receipts['closing'])->toBe('1250.0000')
        ->and($receipts['cash_accounts'])->toHaveCount(1);

    $balanceSheet = app(BalanceSheetStatement::class);
    expect($balanceSheet->build($this->admin, ['branch_id' => $this->branchB->id, 'as_of' => '2026-01-31'])['total_assets'])->toBe('250.0000')
        ->and($balanceSheet->build($this->admin, ['as_of' => '2026-01-31'])['total_assets'])->toBe('1250.0000');

    $branches = app(BranchIncomeExpenditureStatement::class)->build($this->admin, ['from' => '2026-01-01', 'to' => '2026-01-31']);
    expect($branches['total_income']['branch_'.$this->branchA->id])->toBe('1000.0000')
        ->and($branches['total_income']['branch_'.$this->branchB->id])->toBe('250.0000')
        ->and($branches['total_income'][BranchIncomeExpenditureStatement::TOTAL])->toBe('1250.0000');

    $csv = $this->actingAs($this->admin)->get(route('reports.books.export', ['kind' => 'cash', 'format' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain('Totals')->toContain('1250.0000');
});

test('a reversed receipt nets to zero in the cash book and the account balance', function () {
    $original = branchCashReceipt($this, $this->cashA, '1000.0000');
    $transaction = OperationalTransaction::query()->where('journal_entry_id', $original->id)->firstOrFail();
    app(OperationalTransactionService::class)->reverse($transaction, AccountingPeriod::query()->firstOrFail(), '2026-01-20', 'Receipt entered in error', $this->admin);

    $book = $this->actingAs($this->admin)->get(route('reports.books', ['kind' => 'cash', 'financial_account_id' => $this->cashA->id]))->assertOk();
    $book->assertSee($original->voucher_number);
    $lines = JournalLine::query()->where('account_id', $this->cashLedger->id)->whereHas('journalEntry', fn ($q) => $q->where('branch_id', $this->branchA->id))->get();
    expect($lines)->toHaveCount(2);

    $this->actingAs($this->admin)->get(route('financial-accounts.index'))->assertOk()->assertSeeInOrder(['Cash - Shahrah-e-Faisal', '0.00']);
    expect(branchCashTrialBalance($this, [$this->branchA->id])[0])->toBe('0.0000');
});

test('cash payment actions follow the operational status workflow', function () {
    $service = app(OperationalTransactionService::class);
    $payment = $service->create([
        'type' => OperationalTransactionType::CashPayment->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Supplier',
        'amount' => '100.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->expense->id,
        'narration' => 'Payment workflow visibility fixture',
    ], $this->admin);

    $this->actingAs($this->admin)->get(route('transactions.show', $payment))->assertOk()
        ->assertSee('Submit for approval')->assertDontSee('Post to Journal Engine');

    $service->submit($payment, $this->admin);
    $approver = branchCashUser(SystemRole::Administrator, $this->branchA);
    $this->actingAs($approver)->get(route('transactions.show', $payment->fresh()))->assertOk()
        ->assertSee('Approve')->assertSee('Return for correction')->assertDontSee('Post to Journal Engine');

    $service->approve($payment->fresh(), $approver);
    $this->actingAs($approver)->get(route('transactions.show', $payment->fresh()))->assertOk()
        ->assertSee('Confirm disbursement')->assertDontSee('Post to Journal Engine');

    $service->disburse($payment->fresh(), $approver);
    $this->actingAs($approver)->get(route('transactions.show', $payment->fresh()))->assertOk()
        ->assertSee('Post to Journal Engine')->assertDontSee('Confirm disbursement');

    $service->post($payment->fresh(), $approver);
    $this->actingAs($approver)->get(route('transactions.show', $payment->fresh()))->assertOk()
        ->assertSee('Reverse')->assertDontSee('Submit for approval')->assertDontSee('Confirm disbursement')->assertDontSee('Post to Journal Engine');
});

test('cash receipt retains authorized direct posting while draft', function () {
    $receipt = app(OperationalTransactionService::class)->create([
        'type' => OperationalTransactionType::CashReceipt->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Client',
        'amount' => '100.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->income->id,
        'narration' => 'Receipt workflow visibility fixture',
    ], $this->admin);

    $this->actingAs($this->admin)->get(route('transactions.show', $receipt))->assertOk()
        ->assertSee('Post to Journal Engine')->assertDontSee('Submit for approval');
});

test('bank payment draft does not expose direct journal posting', function () {
    $payment = app(OperationalTransactionService::class)->create([
        'type' => OperationalTransactionType::BankPayment->value,
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Supplier',
        'amount' => '100.0000',
        'financial_account_id' => $this->bankA->id,
        'contra_account_id' => $this->expense->id,
        'narration' => 'Bank payment workflow visibility fixture',
    ], $this->admin);

    $this->actingAs($this->admin)->get(route('transactions.show', $payment))->assertOk()
        ->assertSee('Submit for approval')->assertDontSee('Post to Journal Engine');
});
