<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PaymentBehaviour;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->admin->branches()->attach($this->branch);

    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->checker->branches()->attach($this->branch);

    $this->expenseLedger = Account::factory()->create([
        'account_type' => 'expense',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
        'direct_payment_eligible' => true,
    ]);

    $this->ineligibleExpense = Account::factory()->create([
        'account_type' => 'expense',
        'financial_statement' => 'income_expenditure',
        'direct_payment_eligible' => false,
    ]);

    $this->payableLedger = Account::factory()->create([
        'account_type' => 'liability',
        'normal_balance' => 'credit',
    ]);
    AccountMapping::query()->create([
        'mapping_key' => AccountMappingKey::AccountsPayable->value,
        'account_id' => $this->payableLedger->id,
        'updated_by' => $this->admin->id,
    ]);

    $this->bankAccount = FinancialAccount::factory()->create([
        'type' => 'bank',
        'branch_id' => $this->branch,
        'account_id' => Account::factory()->create(),
    ]);

    $this->cashAccount = FinancialAccount::factory()->create([
        'type' => 'cash',
        'branch_id' => $this->branch,
        'account_id' => Account::factory()->create(),
    ]);

    $this->vendor = Vendor::factory()->create();
    $this->vendorType = PayeeType::query()->where('code', 'vendor')->firstOrFail();

    $this->directoryType = PayeeType::query()->create(['name' => 'Supplier Directory', 'code' => 'supp_dir', 'source_kind' => 'directory', 'is_active' => true]);
    $this->directoryPayee = Payee::query()->create(['payee_type_id' => $this->directoryType->id, 'name' => 'Prime Utilities Ltd', 'entity_kind' => 'organization', 'is_active' => true]);

    $this->actingAs($this->admin);
});

test('1. new accrued expense creation works via web POST and creates balanced journal and payable obligation upon posting', function () {
    $response = $this->post(route('transactions.store'), [
        'type' => 'expense',
        'settlement_type' => 'accrued',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-10',
        'amount' => '1500.00',
        'contra_account_id' => $this->expenseLedger->id,
        'payable_account_id' => $this->payableLedger->id,
        'party_name' => 'Electricity Utility',
        'narration' => 'Monthly electricity bill accrual',
    ]);
    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $expense = OperationalTransaction::query()->where('narration', 'Monthly electricity bill accrual')->firstOrFail();
    expect($expense->status)->toBe(OperationalTransactionStatus::Draft)
        ->and($expense->settlement_type)->toBe('accrued')
        ->and($expense->financial_account_id)->toBeNull();

    $service = app(OperationalTransactionService::class);
    $service->submit($expense, $this->admin);
    $service->approve($expense->fresh(), $this->checker);
    $posted = $service->post($expense->fresh(), $this->checker);

    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($posted->journalEntry)->not->toBeNull()
        ->and(AccountingDecimal::of($posted->journalEntry->total_debit)->isEqualTo(AccountingDecimal::of($posted->journalEntry->total_credit)))->toBeTrue()
        ->and($posted->accruedPayableObligation)->not->toBeNull()
        ->and(AccountingDecimal::of($posted->accruedPayableObligation->original_amount)->isEqualTo(AccountingDecimal::of('1500.0000')))->toBeTrue();
});

test('2. existing accrued expense journal and obligation behavior remains correct', function () {
    $service = app(OperationalTransactionService::class);
    $expense = $service->create([
        'type' => 'expense',
        'settlement_type' => 'accrued',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-12',
        'amount' => '800.0000',
        'contra_account_id' => $this->expenseLedger->id,
        'payable_account_id' => $this->payableLedger->id,
        'narration' => 'Internet fee accrual',
    ], $this->admin);
    $service->submit($expense, $this->admin);
    $service->approve($expense->fresh(), $this->checker);
    $posted = $service->post($expense->fresh(), $this->checker);

    $obligation = $posted->accruedPayableObligation;
    expect($obligation)->not->toBeNull()
        ->and(AccountingDecimal::of($obligation->outstandingAmount())->isEqualTo(AccountingDecimal::of('800.0000')))->toBeTrue();
});

test('3. new paid expense creation through legacy POST is rejected with clear message pointing to Payments', function () {
    $beforeCount = OperationalTransaction::query()->count();
    $response = $this->post(route('transactions.store'), [
        'type' => 'expense',
        'settlement_type' => 'paid',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '500.00',
        'financial_account_id' => $this->cashAccount->id,
        'contra_account_id' => $this->expenseLedger->id,
        'narration' => 'Attempted legacy paid expense',
    ]);

    $response->assertSessionHasErrors([
        'settlement_type' => 'Direct payments must be created from Payments. Expenses is used to record accrued expenses.',
    ]);
    expect(OperationalTransaction::query()->count())->toBe($beforeCount);
});

test('4. direct expense through unified payments still works via Bank and Cash', function () {
    $directExpensePurpose = PaymentPurpose::query()->where('behaviour', PaymentBehaviour::DirectExpense)->firstOrFail();

    $response = $this->post(route('payments.store'), [
        'payment_purpose_id' => $directExpensePurpose->id,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-16',
        'payment_source' => 'bank',
        'financial_account_id' => $this->bankAccount->id,
        'debit_account_id' => $this->expenseLedger->id,
        'payee_type_id' => $this->directoryType->id,
        'payee_id' => $this->directoryPayee->id,
        'amount' => '1200.00',
        'narration' => 'Direct bank utility payment',
    ]);
    $response->assertSessionHasNoErrors();

    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->type)->toBe(OperationalTransactionType::BankPayment)
        ->and($payment->contra_account_id)->toBe($this->expenseLedger->id)
        ->and($payment->payment_purpose_id)->toBe($directExpensePurpose->id)
        ->and((float) $payment->amount)->toEqual(1200.0);
});

test('5. direct payment eligible restriction remains enforced on Direct Expense', function () {
    $directExpensePurpose = PaymentPurpose::query()->where('behaviour', PaymentBehaviour::DirectExpense)->firstOrFail();
    $beforeCount = OperationalTransaction::query()->count();

    $response = $this->post(route('payments.store'), [
        'payment_purpose_id' => $directExpensePurpose->id,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-16',
        'payment_source' => 'bank',
        'financial_account_id' => $this->bankAccount->id,
        'debit_account_id' => $this->ineligibleExpense->id,
        'payee_type_id' => $this->directoryType->id,
        'payee_id' => $this->directoryPayee->id,
        'amount' => '500.00',
        'narration' => 'Ineligible debit attempt',
    ]);

    $response->assertSessionHasErrors('debit_account_id');
    expect(OperationalTransaction::query()->count())->toBe($beforeCount);
});

test('6. unauthorized user cannot bypass permission checks on unified payments', function () {
    $unauthorizedUser = User::factory()->create();
    $unauthorizedUser->branches()->attach($this->branch);
    $this->actingAs($unauthorizedUser);

    $this->get(route('payments.create'))->assertForbidden();

    $directExpensePurpose = PaymentPurpose::query()->where('behaviour', PaymentBehaviour::DirectExpense)->firstOrFail();
    $this->post(route('payments.store'), [
        'payment_purpose_id' => $directExpensePurpose->id,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-16',
        'payment_source' => 'bank',
        'financial_account_id' => $this->bankAccount->id,
        'debit_account_id' => $this->expenseLedger->id,
        'payee_type_id' => $this->directoryType->id,
        'payee_id' => $this->directoryPayee->id,
        'amount' => '500.00',
    ])->assertForbidden();
});

test('7. expense-only role retains accrued expense access without gaining payment rights', function () {
    $role = Role::create(['name' => 'Expense Clerk', 'guard_name' => 'web']);
    $role->givePermissionTo(['expenses.view', 'expenses.create']);

    $expenseClerk = User::factory()->create();
    $expenseClerk->assignRole($role);
    $expenseClerk->branches()->attach($this->branch);
    $this->actingAs($expenseClerk);

    $indexResponse = $this->get(route('transactions.index', 'expense'))->assertOk();
    $indexResponse->assertSee('Create Expense');
    $indexResponse->assertDontSee('Make Direct Payment');

    $createResponse = $this->get(route('transactions.create', 'expense'))->assertOk();
    $createResponse->assertSee('Record as payable');
    $createResponse->assertDontSee('href="'.route('payments.create').'"', false);

    $this->get(route('payments.create'))->assertForbidden();

    $this->post(route('transactions.store'), [
        'type' => 'expense',
        'settlement_type' => 'accrued',
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-17',
        'amount' => '300.00',
        'contra_account_id' => $this->expenseLedger->id,
        'payable_account_id' => $this->payableLedger->id,
        'narration' => 'Clerk recorded accrued expense',
    ])->assertSessionHasNoErrors();
});

test('8. historical paid expense records remain accessible and reversible under existing rules', function () {
    $service = app(OperationalTransactionService::class);
    $historical = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branch->id,
        'amount' => '900.0000',
        'financial_account_id' => $this->cashAccount->id,
        'contra_account_id' => $this->expenseLedger->id,
        'party_name' => 'Historical Vendor',
        'narration' => 'Legacy historical paid expense',
        'created_by' => $this->admin->id,
        'approval_tier' => 'standard',
    ]);

    $service->submit($historical, $this->admin);
    $service->approve($historical->fresh(), $this->checker);
    $service->disburse($historical->fresh(), $this->checker);
    $posted = $service->post($historical->fresh(), $this->checker);

    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($posted->journalEntry)->not->toBeNull();

    $this->get(route('transactions.show', $posted))->assertOk()
        ->assertSee('Legacy historical paid expense');

    $response = $this->post(route('transactions.reverse', $posted), [
        'accounting_period_id' => $this->period->id,
        'transaction_date' => '2026-01-25',
        'reason' => 'Reversing incorrect historical paid expense',
    ]);
    $response->assertSessionHasNoErrors();

    expect($posted->fresh()->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and($posted->fresh()->journalEntry->status->value)->toBe('reversed');
});

test('9. direct expense creates no duplicate expense journal or payment', function () {
    $directExpensePurpose = PaymentPurpose::query()->where('behaviour', PaymentBehaviour::DirectExpense)->firstOrFail();
    $journalsBefore = JournalEntry::query()->count();
    $expensesBefore = OperationalTransaction::query()->where('type', OperationalTransactionType::Expense)->count();

    $response = $this->post(route('payments.store'), [
        'payment_purpose_id' => $directExpensePurpose->id,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-18',
        'payment_source' => 'bank',
        'financial_account_id' => $this->bankAccount->id,
        'debit_account_id' => $this->expenseLedger->id,
        'payee_type_id' => $this->directoryType->id,
        'payee_id' => $this->directoryPayee->id,
        'amount' => '750.00',
        'narration' => 'Single unified payment check',
    ]);
    $response->assertSessionHasNoErrors();

    $expensesAfter = OperationalTransaction::query()->where('type', OperationalTransactionType::Expense)->count();
    expect($expensesAfter)->toBe($expensesBefore);

    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->type)->toBe(OperationalTransactionType::BankPayment)
        ->and($payment->status)->toBe(OperationalTransactionStatus::Draft);

    $service = app(OperationalTransactionService::class);
    $service->submit($payment, $this->admin);
    $service->approve($payment->fresh(), $this->checker);
    $service->disburse($payment->fresh(), $this->checker);
    $service->post($payment->fresh(), $this->checker);

    $journalsAfter = JournalEntry::query()->count();
    expect($journalsAfter)->toBe($journalsBefore + 1);
});

test('10. other existing payment purposes remain functional', function () {
    $bills = app(PurchaseBillService::class);
    $bill = $bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payableLedger->id,
        'bill_number' => 'BILL-PURPOSE-TEST',
        'invoice_date' => '2026-01-05',
        'lines' => [[
            'account_id' => $this->expenseLedger->id,
            'description' => 'Supplies',
            'quantity' => '1.0000',
            'unit_amount' => '600.0000',
        ]],
    ], $this->admin);
    $bills->submit($bill, $this->admin);
    $bills->approve($bill->fresh(), $this->checker);
    $postedBill = $bills->post($bill->fresh(), $this->checker);

    $vendorBillPurpose = PaymentPurpose::query()->where('behaviour', PaymentBehaviour::VendorBill)->firstOrFail();

    $response = $this->post(route('payments.store'), [
        'payment_purpose_id' => $vendorBillPurpose->id,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-20',
        'payment_source' => 'bank',
        'financial_account_id' => $this->bankAccount->id,
        'payee_type_id' => $this->vendorType->id,
        'payee_vendor_id' => $this->vendor->id,
        'purchase_bill_id' => $postedBill->id,
        'amount' => '600.00',
        'narration' => 'Paying vendor bill via unified payment',
    ]);
    $response->assertSessionHasNoErrors();

    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->type)->toBe(OperationalTransactionType::VendorPayment)
        ->and($payment->purchase_bill_id)->toBe($postedBill->id);

    $service = app(OperationalTransactionService::class);
    $service->submit($payment, $this->admin);
    $service->approve($payment->fresh(), $this->checker);
    $service->disburse($payment->fresh(), $this->checker);
    $service->post($payment->fresh(), $this->checker);

    expect(AccountingDecimal::of($postedBill->fresh()->outstandingAmount())->isEqualTo(AccountingDecimal::of('0.0000')))->toBeTrue();
});

test('11. expense form settlement helper text renders single clean message without literal @else for both authorized and unauthorized users', function () {
    // 1. Authorized user (admin with payments.create + active direct expense purpose)
    $this->actingAs($this->admin);
    $authResponse = $this->get(route('transactions.create', 'expense'))->assertOk();
    $authResponse->assertSee('To pay directly via Bank, Cash, or Petty Cash, use <a href="'.route('payments.create').'">Unified Payments</a>.', false);
    $authResponse->assertDontSee('@else');
    $authResponse->assertDontSee('Direct payments must be created from Unified Payments.');

    // 2. Unauthorized user (expense clerk without payment rights)
    $role = Role::firstOrCreate(['name' => 'Expense Clerk', 'guard_name' => 'web']);
    $role->syncPermissions(['expenses.view', 'expenses.create']);

    $expenseClerk = User::factory()->create();
    $expenseClerk->assignRole($role);
    $expenseClerk->branches()->attach($this->branch);
    $this->actingAs($expenseClerk);

    $unauthResponse = $this->get(route('transactions.create', 'expense'))->assertOk();
    $unauthResponse->assertSee('Direct payments must be created from Unified Payments.');
    $unauthResponse->assertDontSee('@else');
    $unauthResponse->assertDontSee('href="'.route('payments.create').'"', false);
    $unauthResponse->assertDontSee('To pay directly via Bank, Cash, or Petty Cash');
});
