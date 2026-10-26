<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()->create()]);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()->create()]);
    $this->vendor = Vendor::factory()->create();
    // Starting configuration from the migration.
    $this->vendorType = PayeeType::query()->where('code', 'vendor')->firstOrFail();
    $this->employeeType = PayeeType::query()->where('code', 'employee')->firstOrFail();
    $this->landlords = PayeeType::query()->create(['name' => 'Landlord', 'code' => 'landlord', 'source_kind' => 'directory', 'is_active' => true]);
    $this->landlord = Payee::query()->create(['payee_type_id' => $this->landlords->id, 'name' => 'Gulshan Properties', 'entity_kind' => 'organization', 'is_active' => true]);
    $this->actingAs($this->maker);
});

function purposeId(string $code): int
{
    return PaymentPurpose::query()->where('code', $code)->value('id');
}

function unifiedPayment(object $test, string $purpose, array $overrides = []): array
{
    return ['payment_purpose_id' => purposeId($purpose), 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-20', 'financial_account_id' => $test->bank->id, 'amount' => '100.00', ...$overrides];
}

function unifiedPostedBill(object $test, string $amount = '500.0000'): PurchaseBill
{
    $bills = app(PurchaseBillService::class);
    $bill = $bills->create(['vendor_id' => $test->vendor->id, 'branch_id' => $test->branch->id, 'payable_account_id' => $test->payable->id, 'bill_number' => 'UP-'.Str::random(6), 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $test->expense->id, 'description' => 'Unified', 'quantity' => '1.0000', 'unit_amount' => $amount]]], $test->maker);
    $bills->submit($bill, $test->maker);
    $bills->approve($bill->fresh(), $test->checker);

    return $bills->post($bill->fresh(), $test->checker);
}

test('a vendor bill payment checks the vendor payee against the bill and keeps the vendor workflow limits', function () {
    $bill = unifiedPostedBill($this);
    $vendorPayee = ['payee_type_id' => $this->vendorType->id, 'payee_vendor_id' => $this->vendor->id, 'purchase_bill_id' => $bill->id];

    $this->post(route('payments.store'), unifiedPayment($this, 'vendor_bill', [...$vendorPayee, 'amount' => '200.00']))->assertSessionHasNoErrors();
    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->type)->toBe(OperationalTransactionType::VendorPayment)->and($payment->purchase_bill_id)->toBe($bill->id)
        ->and((int) $payment->vendor_id)->toBe($this->vendor->id)->and($payment->payment_purpose_id)->toBe(purposeId('vendor_bill'))
        ->and($payment->payee_type_id)->toBe($this->vendorType->id)
        ->and($payment->payee_snapshot)->toMatchArray(['purpose' => ['id' => purposeId('vendor_bill'), 'code' => 'vendor_bill', 'name' => 'Vendor bill payment', 'behaviour' => 'vendor_bill']])
        ->and($payment->payee_snapshot['payee']['name'])->toBe($this->vendor->name);

    $other = Vendor::factory()->create();
    $this->post(route('payments.store'), unifiedPayment($this, 'vendor_bill', [...$vendorPayee, 'payee_vendor_id' => $other->id]))->assertSessionHasErrors('purchase_bill_id');
    $this->post(route('payments.store'), unifiedPayment($this, 'vendor_bill', [...$vendorPayee, 'payee_type_id' => $this->landlords->id, 'payee_id' => $this->landlord->id]))->assertSessionHasErrors('payee_type_id');
    $this->post(route('payments.store'), unifiedPayment($this, 'vendor_bill', [...$vendorPayee, 'amount' => '300.01']))->assertSessionHasErrors('amount');
    $this->post(route('payments.store'), unifiedPayment($this, 'vendor_bill', [...$vendorPayee, 'branch_id' => $this->otherBranch->id]))->assertSessionHasErrors('branch_id');
    expect(OperationalTransaction::query()->count())->toBe(1);
});

test('an accrued payable without a vendor is paid to a directory payee, once per request token', function () {
    $service = app(OperationalTransactionService::class);
    $expense = $service->create(['type' => 'expense', 'settlement_type' => 'accrued', 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-05', 'amount' => '400.0000',
        'contra_account_id' => $this->expense->id, 'payable_account_id' => $this->payable->id, 'narration' => 'Accrued rent'], $this->maker);
    $service->submit($expense, $this->maker);
    $service->approve($expense->fresh(), $this->checker);
    $service->post($expense->fresh(), $this->checker);
    $obligation = PayableObligation::query()->firstOrFail();
    $data = unifiedPayment($this, 'accrued_payable', ['payee_type_id' => $this->landlords->id, 'payee_id' => $this->landlord->id, 'payable_obligation_id' => $obligation->id,
        'settlement_request_token' => (string) Str::uuid(), 'amount' => '150.00']);

    $this->post(route('payments.store'), $data)->assertSessionHasNoErrors();
    $this->post(route('payments.store'), $data)->assertSessionHasNoErrors();
    $settlements = OperationalTransaction::query()->where('payable_obligation_id', $obligation->id)->get();
    expect($settlements)->toHaveCount(1)->and($settlements->first()->contra_account_id)->toBe($this->payable->id)
        ->and($settlements->first()->payee_id)->toBe($this->landlord->id)->and($settlements->first()->party_name)->toBe('Gulshan Properties');

    // A vendor payee cannot settle a payable that has no vendor; more than the outstanding amount is refused.
    $this->post(route('payments.store'), [...$data, 'payee_type_id' => $this->vendorType->id, 'payee_vendor_id' => $this->vendor->id, 'settlement_request_token' => (string) Str::uuid()])->assertSessionHasErrors('payable_obligation_id');
    $this->post(route('payments.store'), [...$data, 'settlement_request_token' => (string) Str::uuid(), 'amount' => '400.01'])->assertSessionHasErrors('amount');
});

test('a direct expense debits only an active expense ledger, or the one fixed by its purpose', function () {
    $landlordPayee = ['payee_type_id' => $this->landlords->id, 'payee_id' => $this->landlord->id];
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'debit_account_id' => $this->expense->id]))->assertSessionHasNoErrors();
    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->type)->toBe(OperationalTransactionType::BankPayment)->and($payment->contra_account_id)->toBe($this->expense->id)
        ->and($payment->payee_id)->toBe($this->landlord->id)->and($payment->payee_snapshot['payee_type']['name'])->toBe('Landlord');

    foreach ([$this->payable, $this->income] as $notExpense) {
        $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'debit_account_id' => $notExpense->id]))->assertSessionHasErrors('debit_account_id');
    }
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'contra_account_id' => $this->payable->id]))->assertSessionHasErrors('debit_account_id');

    // A purpose with a fixed ledger always uses it, whatever the request says.
    $rentLedger = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $rent = PaymentPurpose::query()->create(['name' => 'Rent', 'code' => 'rent', 'behaviour' => 'direct_expense', 'debit_account_id' => $rentLedger->id, 'is_active' => true]);
    $this->post(route('payments.store'), [...unifiedPayment($this, 'rent', $landlordPayee), 'debit_account_id' => $this->payable->id])->assertSessionHasNoErrors();
    expect(OperationalTransaction::query()->latest('id')->value('contra_account_id'))->toBe($rentLedger->id);

    // Renaming the purpose later does not change what the payment shows.
    $rent->update(['name' => 'Office Rent']);
    expect(OperationalTransaction::query()->latest('id')->firstOrFail()->payee_snapshot['purpose']['name'])->toBe('Rent');
});

test('payees must be active records of their type, and only bank accounts, active and permitted purposes are accepted', function () {
    $landlordPayee = ['payee_type_id' => $this->landlords->id, 'payee_id' => $this->landlord->id, 'debit_account_id' => $this->expense->id];
    $otherDirectory = PayeeType::query()->create(['name' => 'Consultant', 'code' => 'consultant', 'source_kind' => 'directory', 'is_active' => true]);

    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'payee_type_id' => $otherDirectory->id]))->assertSessionHasErrors('payee_id');
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', ['debit_account_id' => $this->expense->id]))->assertSessionHasErrors('payee_type_id');
    $this->landlord->update(['is_active' => false]);
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', $landlordPayee))->assertSessionHasErrors('payee_id');
    $this->landlord->update(['is_active' => true]);
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'financial_account_id' => $this->cash->id]))->assertSessionHasErrors('financial_account_id');
    PaymentPurpose::query()->where('code', 'direct_expense')->update(['is_active' => false]);
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', $landlordPayee))->assertSessionHasErrors('payment_purpose_id');
    PaymentPurpose::query()->where('code', 'direct_expense')->update(['is_active' => true]);

    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $approver->branches()->attach($this->branch);
    $this->actingAs($approver)->get(route('payments.create'))->assertForbidden();
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', $landlordPayee))->assertForbidden();
    expect(OperationalTransaction::query()->count())->toBe(0);

    $this->actingAs($this->maker)->get(route('payments.create'))->assertOk()->assertSee('Vendor bill payment')->assertSee('Landlord')->assertSee('Gulshan Properties')
        ->assertSee('Select a branch and vendor to view eligible outstanding purchase bills.')->assertSee('data-need-payee="Select a vendor first"', false)
        ->assertSee('No outstanding posted purchase bills for this vendor.')->assertDontSee('The vendor is determined by the selected bill');
});

test('admins configure purposes and payee types within the protected behaviours and sources, with audit and history locks', function () {
    $this->post(route('payment-purposes.store'), ['name' => 'Bad', 'code' => 'bad', 'behaviour' => 'vendor_bill', 'debit_account_id' => $this->expense->id, 'is_active' => '1'])->assertSessionHasErrors('debit_account_id');
    $this->post(route('payment-purposes.store'), ['name' => 'Bad', 'code' => 'bad', 'behaviour' => 'direct_expense', 'debit_account_id' => $this->payable->id, 'is_active' => '1'])->assertSessionHasErrors('debit_account_id');
    $this->post(route('payment-purposes.store'), ['name' => 'Bad', 'code' => 'bad', 'behaviour' => 'custom_gl', 'is_active' => '1'])->assertSessionHasErrors('behaviour');
    $this->post(route('payment-purposes.store'), ['name' => 'Utilities', 'code' => 'utilities', 'behaviour' => 'direct_expense', 'debit_account_id' => $this->expense->id, 'is_active' => '1'])->assertSessionHasNoErrors();
    $this->post(route('payment-purposes.store'), ['name' => 'Dup', 'code' => 'utilities', 'behaviour' => 'direct_expense', 'is_active' => '1'])->assertSessionHasErrors('code');
    $utilities = PaymentPurpose::query()->where('code', 'utilities')->firstOrFail();
    expect(AuditLog::query()->where('event', 'payment_purposes.created')->where('auditable_id', $utilities->id)->exists())->toBeTrue();

    // Once a payment uses it, its behaviour and ledger are frozen but its name can change.
    $this->post(route('payments.store'), unifiedPayment($this, 'utilities', ['payee_type_id' => $this->landlords->id, 'payee_id' => $this->landlord->id]))->assertSessionHasNoErrors();
    $this->put(route('payment-purposes.update', $utilities), ['name' => 'Utilities', 'code' => 'utilities', 'behaviour' => 'staff_advance', 'is_active' => '1'])->assertSessionHasErrors('behaviour');
    $this->put(route('payment-purposes.update', $utilities), ['name' => 'Electricity & Gas', 'code' => 'utilities', 'behaviour' => 'direct_expense', 'debit_account_id' => $this->expense->id, 'is_active' => '1'])->assertSessionHasNoErrors();
    expect($utilities->fresh()->name)->toBe('Electricity & Gas')->and($utilities->fresh()->behaviour->value)->toBe('direct_expense');

    $this->post(route('payee-types.store'), ['name' => 'Government Authority', 'code' => 'government', 'source_kind' => 'directory', 'is_active' => '1'])->assertSessionHasNoErrors();
    $this->post(route('payee-types.store'), ['name' => 'X', 'code' => 'x', 'source_kind' => 'custom_api', 'is_active' => '1'])->assertSessionHasErrors('source_kind');
    $this->put(route('payee-types.update', $this->landlords), ['name' => 'Landlords', 'code' => 'landlord', 'source_kind' => 'vendor', 'is_active' => '1'])->assertSessionHasErrors('source_kind');
    $this->post(route('payees.store'), ['payee_type_id' => $this->vendorType->id, 'name' => 'Copy of vendor', 'entity_kind' => 'organization', 'is_active' => '1'])->assertSessionHasErrors('payee_type_id');

    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $this->actingAs($approver)->post(route('payment-purposes.store'), ['name' => 'Y', 'code' => 'y', 'behaviour' => 'direct_expense', 'is_active' => '1'])->assertForbidden();
});

test('direct expense offers and accepts only expense ledgers approved for direct payment, including a purpose fixed ledger', function () {
    $landlordPayee = ['payee_type_id' => $this->landlords->id, 'payee_id' => $this->landlord->id];
    $salaries = Account::factory()->create(['code' => '51101', 'name' => 'Teaching Staff Salaries', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $depreciation = Account::factory()->create(['code' => '55101', 'name' => 'Depreciation - Buildings', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);

    $form = $this->get(route('payments.create'))->assertOk()->getContent();
    expect($form)->toContain('value="'.$this->expense->id.'"')->not->toContain('Teaching Staff Salaries')->not->toContain('Depreciation - Buildings');

    // A tampered request naming an unapproved expense ledger is refused.
    foreach ([$salaries, $depreciation] as $unapproved) {
        $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'debit_account_id' => $unapproved->id]))->assertSessionHasErrors('debit_account_id');
    }
    // A purpose whose fixed ledger is not (or no longer) approved cannot be used, and cannot be configured.
    $payroll = PaymentPurpose::query()->create(['name' => 'Staff salary', 'code' => 'staff_salary', 'behaviour' => 'direct_expense', 'debit_account_id' => $salaries->id, 'is_active' => true]);
    $this->post(route('payments.store'), unifiedPayment($this, 'staff_salary', $landlordPayee))->assertSessionHasErrors('debit_account_id');
    $this->post(route('payment-purposes.store'), ['name' => 'Depreciation', 'code' => 'depr', 'behaviour' => 'direct_expense', 'debit_account_id' => $depreciation->id, 'is_active' => '1'])->assertSessionHasErrors('debit_account_id');
    $this->expense->update(['direct_payment_eligible' => false]);
    $this->post(route('payments.store'), unifiedPayment($this, 'direct_expense', [...$landlordPayee, 'debit_account_id' => $this->expense->id]))->assertSessionHasErrors('debit_account_id');
    expect(OperationalTransaction::query()->count())->toBe(0);
    $this->get(route('payments.create'))->assertOk()->assertSee('No expense ledgers are approved for direct payment yet.');

    // Approval is an audited account setting, and only an expense ledger can receive it.
    $subGroup = fn (string $type, string $statement) => Account::factory()->create(['level' => 'sub_group', 'account_type' => $type, 'financial_statement' => $statement, 'is_postable' => false])->id;
    $depreciation->update(['parent_id' => $subGroup('expense', 'income_expenditure')]);
    $this->payable->update(['parent_id' => $subGroup('liability', 'balance_sheet')]);
    $account = fn (Account $ledger, array $overrides) => ['name' => $ledger->name, 'is_active' => '1', 'is_postable' => '1', ...$overrides];
    $this->put(route('accounts.update', $depreciation), $account($depreciation, ['direct_payment_eligible' => '1']))->assertSessionHasNoErrors();
    expect($depreciation->fresh()->direct_payment_eligible)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.updated')->where('auditable_id', $depreciation->id)->latest('id')->value('properties')['after']['direct_payment_eligible'])->toBeTrue();
    $this->put(route('accounts.update', $this->payable), $account($this->payable, ['direct_payment_eligible' => '1']))->assertSessionHasErrors('direct_payment_eligible');
    expect($this->payable->fresh()->direct_payment_eligible)->toBeFalse()->and($payroll->fresh()->debit_account_id)->toBe($salaries->id);
});
