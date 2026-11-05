<?php

use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\ImprestFloat;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\OperationalTransactionService;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);

    $this->branch = Branch::factory()->create([
        'name' => 'Head Office Karachi',
        'status' => 'publish',
    ]);

    $this->admin->branches()->attach($this->branch->id);

    $this->fiscalYear = FiscalYear::factory()->create([
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->fiscalYear->id,
        'period_number' => 1,
        'name' => 'January 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    // Setup Cash Account (11102) and Bank Account (11201)
    $this->cashGlAccount = Account::factory()->create([
        'code' => '11102',
        'name' => 'Cash in Hand',
        'financial_account_type' => 'cash',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->pettyCashGlAccount = Account::factory()->create([
        'code' => '11103',
        'name' => 'Petty Cash Ledger',
        'financial_account_type' => 'cash',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->bankGlAccount = Account::factory()->create([
        'code' => '11201',
        'name' => 'Meezan Bank Main',
        'financial_account_type' => 'bank',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->incomeGlAccount = Account::factory()->create([
        'code' => '41001',
        'name' => 'General Therapy Income',
        'account_type' => 'income',
        'normal_balance' => 'credit',
        'financial_statement' => 'income_expenditure',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->cashFinancialAccount = FinancialAccount::factory()->create([
        'name' => 'Main Cash Drawer',
        'type' => 'cash',
        'currency' => 'PKR',
        'branch_id' => $this->branch->id,
        'account_id' => $this->cashGlAccount->id,
        'is_active' => true,
    ]);

    $this->pettyCashFinancialAccount = FinancialAccount::factory()->create([
        'name' => 'Petty Cash Box',
        'type' => 'cash',
        'currency' => 'PKR',
        'branch_id' => $this->branch->id,
        'account_id' => $this->pettyCashGlAccount->id,
        'is_active' => true,
    ]);

    $this->bankFinancialAccount = FinancialAccount::factory()->create([
        'name' => 'Meezan Current Account',
        'type' => 'bank',
        'currency' => 'PKR',
        'branch_id' => $this->branch->id,
        'account_id' => $this->bankGlAccount->id,
        'is_active' => true,
    ]);

    // Make pettyCashFinancialAccount hold an active imprest float
    $this->imprestFloat = ImprestFloat::factory()->create([
        'branch_id' => $this->branch->id,
        'account_id' => $this->pettyCashGlAccount->id,
        'source_financial_account_id' => $this->bankFinancialAccount->id,
        'float_amount' => '25000.00',
        'is_active' => true,
    ]);
});

test('unauthorized user cannot view unified receipts index or create', function () {
    $guestUser = User::factory()->create();

    $this->actingAs($guestUser)->get(route('receipts.index'))->assertForbidden();
    $this->actingAs($guestUser)->get(route('receipts.create'))->assertForbidden();
});

test('authorized user can view receipts register and create screen', function () {
    $this->actingAs($this->admin)->get(route('receipts.index'))->assertOk()->assertSee('Receipts');
    $this->actingAs($this->admin)->get(route('receipts.create'))->assertOk()->assertSee('Receiving Method');
});

test('create receipt screen excludes accounts holding active imprest float', function () {
    $response = $this->actingAs($this->admin)->get(route('receipts.create'))->assertOk();

    // Main Cash Drawer and Meezan Current Account should be present
    $response->assertSee('Main Cash Drawer');
    $response->assertSee('Meezan Current Account');

    // Petty Cash Box (which holds an active imprest float) must NOT be present in dropdown options
    $response->assertDontSee('Petty Cash Box');
});

test('can create cash receipt via unified endpoint and post to journal with CR voucher', function () {
    $vendor = Vendor::factory()->create(['name' => 'Walk-in Benefactor']);
    $fund = Fund::factory()->create(['code' => 'GEN', 'name' => 'General Fund', 'is_active' => true]);

    $payload = [
        'method' => 'cash',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '15000.00',
        'financial_account_id' => $this->cashFinancialAccount->id,
        'contra_account_id' => $this->incomeGlAccount->id,
        'party_name' => 'Haji Muhammad',
        'vendor_id' => $vendor->id,
        'fund_id' => $fund->id,
        'reference_number' => 'REC-2026-001',
        'narration' => 'Cash received for general therapy sponsorship',
    ];

    $response = $this->actingAs($this->admin)->post(route('receipts.store'), $payload);

    $transaction = OperationalTransaction::latest('id')->first();
    expect($transaction)->not->toBeNull();
    $response->assertRedirect(route('transactions.show', $transaction));
    $response->assertSessionHas('status', 'Receipt draft created successfully. Awaiting posting by an authorized user.');

    expect($transaction->type)->toBe(OperationalTransactionType::CashReceipt);
    expect($transaction->status)->toBe(OperationalTransactionStatus::Draft);
    expect(AccountingDecimal::of($transaction->amount)->isEqualTo(AccountingDecimal::of('15000.00')))->toBeTrue();
    expect($transaction->party_name)->toBe('Haji Muhammad');
    expect($transaction->vendor_id)->toBe($vendor->id);
    expect($transaction->fund_id)->toBe($fund->id);

    // Now post the transaction via OperationalTransactionService
    $service = app(OperationalTransactionService::class);
    $service->post($transaction, $this->admin);

    $transaction->refresh();
    expect($transaction->status)->toBe(OperationalTransactionStatus::Posted);
    expect($transaction->journal_entry_id)->not->toBeNull();

    $journal = $transaction->journalEntry;
    expect($journal->status)->toBe(JournalStatus::Posted);
    expect($journal->voucher_number)->toStartWith('CR-');

    // Verify journal lines: Debit Cash in Hand (11102), Credit Income (41001)
    $lines = $journal->lines;
    expect($lines)->toHaveCount(2);

    $debitLine = $lines->firstWhere('account_id', $this->cashGlAccount->id);
    $creditLine = $lines->firstWhere('account_id', $this->incomeGlAccount->id);

    expect($debitLine)->not->toBeNull();
    expect(AccountingDecimal::of($debitLine->debit)->isEqualTo(AccountingDecimal::of('15000.00')))->toBeTrue();

    expect($creditLine)->not->toBeNull();
    expect(AccountingDecimal::of($creditLine->credit)->isEqualTo(AccountingDecimal::of('15000.00')))->toBeTrue();
});

test('can create bank receipt via unified endpoint and post to journal with BR voucher', function () {
    $payload = [
        'method' => 'bank',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-20',
        'amount' => '50000.00',
        'financial_account_id' => $this->bankFinancialAccount->id,
        'contra_account_id' => $this->incomeGlAccount->id,
        'party_name' => 'Corporate Partner Ltd',
        'reference_number' => 'FT-88492019',
        'narration' => 'Online bank transfer received in Meezan Bank',
    ];

    $response = $this->actingAs($this->admin)->post(route('receipts.store'), $payload);

    $transaction = OperationalTransaction::latest('id')->first();
    expect($transaction)->not->toBeNull();
    $response->assertRedirect(route('transactions.show', $transaction));

    expect($transaction->type)->toBe(OperationalTransactionType::BankReceipt);
    expect($transaction->status)->toBe(OperationalTransactionStatus::Draft);

    // Post to journal
    $service = app(OperationalTransactionService::class);
    $service->post($transaction, $this->admin);

    $transaction->refresh();
    expect($transaction->status)->toBe(OperationalTransactionStatus::Posted);

    $journal = $transaction->journalEntry;
    expect($journal->voucher_number)->toStartWith('BR-');

    $lines = $journal->lines;
    $debitLine = $lines->firstWhere('account_id', $this->bankGlAccount->id);
    $creditLine = $lines->firstWhere('account_id', $this->incomeGlAccount->id);

    expect(AccountingDecimal::of($debitLine->debit)->isEqualTo(AccountingDecimal::of('50000.00')))->toBeTrue();
    expect(AccountingDecimal::of($creditLine->credit)->isEqualTo(AccountingDecimal::of('50000.00')))->toBeTrue();
});

test('validation rejects receipt using an imprest float cash account', function () {
    $payload = [
        'method' => 'cash',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '1000.00',
        'financial_account_id' => $this->pettyCashFinancialAccount->id, // holds active imprest float
        'contra_account_id' => $this->incomeGlAccount->id,
        'narration' => 'Attempting to put money directly into petty cash',
    ];

    $response = $this->actingAs($this->admin)->post(route('receipts.store'), $payload);
    $response->assertSessionHasErrors('financial_account_id');
});

test('validation rejects method and account type mismatch', function () {
    // Attempting cash receipt with a bank account
    $payload = [
        'method' => 'cash',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '500.00',
        'financial_account_id' => $this->bankFinancialAccount->id,
        'contra_account_id' => $this->incomeGlAccount->id,
        'narration' => 'Mismatch test',
    ];

    $response = $this->actingAs($this->admin)->post(route('receipts.store'), $payload);
    $response->assertSessionHasErrors('financial_account_id');
});

test('unified receipt register displays both cash and bank receipts with filtering', function () {
    $cr = OperationalTransaction::create([
        'type' => OperationalTransactionType::CashReceipt,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->cashFinancialAccount->id,
        'contra_account_id' => $this->incomeGlAccount->id,
        'transaction_date' => '2026-01-10',
        'amount' => '3000.00',
        'party_name' => 'Cash Donor Alpha',
        'reference_number' => 'CR-REF-01',
        'narration' => 'Donation test alpha',
        'status' => OperationalTransactionStatus::Draft,
        'created_by' => $this->admin->id,
    ]);

    $br = OperationalTransaction::create([
        'type' => OperationalTransactionType::BankReceipt,
        'branch_id' => $this->branch->id,
        'financial_account_id' => $this->bankFinancialAccount->id,
        'contra_account_id' => $this->incomeGlAccount->id,
        'transaction_date' => '2026-01-12',
        'amount' => '7000.00',
        'party_name' => 'Bank Benefactor Beta',
        'reference_number' => 'BR-REF-02',
        'narration' => 'Bank transfer beta',
        'status' => OperationalTransactionStatus::Draft,
        'created_by' => $this->admin->id,
    ]);

    // All receipts
    $response = $this->actingAs($this->admin)->get(route('receipts.index'))->assertOk();
    $response->assertSee('Cash Donor Alpha');
    $response->assertSee('Bank Benefactor Beta');
    $response->assertSee('3,000.00');
    $response->assertSee('7,000.00');

    // Filter by cash receipts only
    $cashResponse = $this->actingAs($this->admin)->get(route('receipts.index', ['type' => 'cash_receipt']))->assertOk();
    $cashResponse->assertSee('Cash Donor Alpha');
    $cashResponse->assertDontSee('Bank Benefactor Beta');

    // Filter by bank receipts only
    $bankResponse = $this->actingAs($this->admin)->get(route('receipts.index', ['type' => 'bank_receipt']))->assertOk();
    $bankResponse->assertDontSee('Cash Donor Alpha');
    $bankResponse->assertSee('Bank Benefactor Beta');

    // Filter by search query
    $searchResponse = $this->actingAs($this->admin)->get(route('receipts.index', ['search' => 'BR-REF-02']))->assertOk();
    $searchResponse->assertDontSee('Cash Donor Alpha');
    $searchResponse->assertSee('Bank Benefactor Beta');
});

test('legacy cash receipt and bank receipt routes remain fully accessible', function () {
    $this->actingAs($this->admin)->get(route('transactions.index', 'cash_receipt'))->assertOk();
    $this->actingAs($this->admin)->get(route('transactions.index', 'bank_receipt'))->assertOk();
    $this->actingAs($this->admin)->get(route('transactions.create', 'cash_receipt'))->assertOk();
    $this->actingAs($this->admin)->get(route('transactions.create', 'bank_receipt'))->assertOk();
});
