<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\ImprestFloat;
use App\Models\OperationalTransaction;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\FeePosting\FeeSettlementMappingService;
use App\Services\JournalWorkflowService;
use App\Services\OperationalTransactionService;
use App\Services\Payments\UnifiedPaymentService;
use App\Services\PettyCash\ImprestLedgerService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->petty = fundedPettyCashAccount($this->branch->id, '1000.0000');
    $this->vendor = Vendor::factory()->create();
    $type = PayeeType::query()->create(['name' => 'Supplier (other)', 'code' => 'supplier_other', 'source_kind' => 'directory', 'is_active' => true]);
    $this->payee = ['payee_type_id' => $type->id, 'payee_id' => Payee::query()->create(['payee_type_id' => $type->id, 'name' => 'Corner Shop', 'entity_kind' => 'organization', 'is_active' => true])->id];
    $this->payments = app(UnifiedPaymentService::class);
    $this->engine = app(OperationalTransactionService::class);
    $this->actingAs($this->maker);
});

function sourcePayment(object $test, string $source, FinancialAccount $account, string $amount, array $overrides = []): OperationalTransaction
{
    return $test->payments->create(['payment_source' => $source, 'payment_purpose_id' => PaymentPurpose::query()->where('code', 'direct_expense')->value('id'),
        'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-20', 'financial_account_id' => $account->id, 'amount' => $amount,
        'debit_account_id' => $test->expense->id, ...$test->payee, ...$overrides], $test->maker);
}

function legacyPayment(object $test, OperationalTransactionType $type, FinancialAccount $account, string $amount, array $overrides = []): OperationalTransaction
{
    return $test->engine->create(['type' => $type->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-20', 'amount' => $amount,
        'financial_account_id' => $account->id, 'contra_account_id' => $test->expense->id, 'narration' => 'Legacy screen fixture', ...$overrides], $test->maker);
}

test('bank, cash and petty cash direct expenses use their own transaction type and account', function () {
    expect(sourcePayment($this, 'bank', $this->bank, '100')->type)->toBe(OperationalTransactionType::BankPayment)
        ->and(sourcePayment($this, 'cash', $this->cash, '100')->type)->toBe(OperationalTransactionType::CashPayment)
        ->and(sourcePayment($this, 'petty_cash', $this->petty, '100')->type)->toBe(OperationalTransactionType::PettyCash)
        ->and(legacyPayment($this, OperationalTransactionType::CashPayment, $this->cash, '50')->type)->toBe(OperationalTransactionType::CashPayment);

    // Each source accepts only its own accounts.
    expect(fn () => sourcePayment($this, 'bank', $this->cash, '10'))->toThrow(ValidationException::class, 'Select a bank account')
        ->and(fn () => sourcePayment($this, 'cash', $this->petty, '10'))->toThrow(ValidationException::class, 'Petty cash floats are paid from the Petty Cash source')
        ->and(fn () => sourcePayment($this, 'petty_cash', $this->cash, '10'))->toThrow(ValidationException::class, 'active petty cash float');
});

test('a cash vendor bill payment uses the vendor payment settlement, and no source but petty cash may pay settlements from a float', function () {
    $bills = app(PurchaseBillService::class);
    $bill = $bills->create(['vendor_id' => $this->vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id, 'bill_number' => 'SRC-1', 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Bill', 'quantity' => '1.0000', 'unit_amount' => '500.0000']]], $this->maker);
    $bills->submit($bill, $this->maker);
    $bills->approve($bill->fresh(), $this->checker);
    $bills->post($bill->fresh(), $this->checker);
    $vendorBill = ['payment_purpose_id' => PaymentPurpose::query()->where('code', 'vendor_bill')->value('id'), 'payee_type_id' => PayeeType::query()->where('code', 'vendor')->value('id'),
        'payee_vendor_id' => $this->vendor->id, 'purchase_bill_id' => $bill->id, 'debit_account_id' => null];

    $payment = sourcePayment($this, 'cash', $this->cash, '200', $vendorBill);
    expect($payment->type)->toBe(OperationalTransactionType::VendorPayment)->and($payment->financial_account_id)->toBe($this->cash->id)
        ->and(fn () => sourcePayment($this, 'cash', $this->cash, '300.01', $vendorBill))->toThrow(ValidationException::class, 'outstanding')
        ->and(fn () => sourcePayment($this, 'petty_cash', $this->petty, '100', $vendorBill))->toThrow(ValidationException::class, 'cannot be paid from this source');

    // Legacy screens cannot pay out of a float's cash account either.
    expect(fn () => legacyPayment($this, OperationalTransactionType::VendorPayment, $this->petty, '100', ['purchase_bill_id' => $bill->id, 'contra_account_id' => null]))->toThrow(ValidationException::class, 'holds an imprest float')
        ->and(fn () => legacyPayment($this, OperationalTransactionType::CashPayment, $this->petty, '100'))->toThrow(ValidationException::class, 'holds an imprest float')
        ->and(fn () => legacyPayment($this, OperationalTransactionType::Expense, $this->petty, '100', ['settlement_type' => 'paid']))->toThrow(ValidationException::class, 'holds an imprest float')
        ->and(fn () => legacyPayment($this, OperationalTransactionType::PettyCash, $this->cash, '100'))->toThrow(ValidationException::class, 'active imprest float');
});

test('petty cash cannot exceed the float: in-approval vouchers reserve it once, returned ones release it, and a voucher never counts against itself', function () {
    expect(fn () => sourcePayment($this, 'petty_cash', $this->petty, '1000.01'))->toThrow(ValidationException::class, 'exceeds the petty cash available');

    $first = sourcePayment($this, 'petty_cash', $this->petty, '600');
    $second = legacyPayment($this, OperationalTransactionType::PettyCash, $this->petty, '700');
    // Drafts reserve nothing, so both could be drafted; whichever is submitted first holds the float.
    $this->engine->submit($first, $this->maker);
    expect(fn () => $this->engine->submit($second->fresh(), $this->maker))->toThrow(ValidationException::class, 'exceeds the petty cash available')
        ->and(fn () => sourcePayment($this, 'petty_cash', $this->petty, '400.01'))->toThrow(ValidationException::class, 'exceeds')
        ->and(sourcePayment($this, 'petty_cash', $this->petty, '400')->status)->toBe(OperationalTransactionStatus::Draft);

    // Returning the first voucher releases its 600, so the second can go ahead.
    $this->engine->reject($first->fresh(), 'Receipt missing, please attach', $this->checker);
    $this->engine->submit($second->fresh(), $this->maker);
    expect(fn () => $this->engine->submit($first->fresh(), $this->maker))->toThrow(ValidationException::class, 'exceeds');

    // The second voucher passes every later step, where it is excluded from its own reservation, and posting moves it into the balance.
    $this->engine->approve($second->fresh(), $this->checker);
    $this->engine->disburse($second->fresh(), $this->checker);
    $posted = $this->engine->post($second->fresh(), $this->checker);
    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)
        ->and(sourcePayment($this, 'petty_cash', $this->petty, '300')->status)->toBe(OperationalTransactionStatus::Draft)
        ->and(fn () => sourcePayment($this, 'petty_cash', $this->petty, '300.01'))->toThrow(ValidationException::class, 'exceeds');

    // A posted voucher can still be reversed, which returns its amount to the float.
    $this->engine->reverse($posted->fresh(), $this->period, '2026-01-25', 'Duplicate voucher', $this->checker);
    expect(sourcePayment($this, 'petty_cash', $this->petty, '1000')->status)->toBe(OperationalTransactionStatus::Draft);
});

test('settlement purposes cannot be paid from petty cash and unknown sources are refused', function () {
    foreach (['vendor_bill', 'accrued_payable', 'salary_payable', 'staff_advance'] as $code) {
        expect(fn () => sourcePayment($this, 'petty_cash', $this->petty, '10', ['payment_purpose_id' => PaymentPurpose::query()->where('code', $code)->value('id')]))
            ->toThrow(ValidationException::class, 'cannot be paid from this source');
    }
    expect(fn () => sourcePayment($this, 'cheque_book', $this->bank, '10'))->toThrow(ValidationException::class, 'Select bank, cash or petty cash');

    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['payments.create']);
    $clerk->branches()->attach($this->branch);
    expect(fn () => $this->payments->create(['payment_source' => 'petty_cash', 'payment_purpose_id' => PaymentPurpose::query()->where('code', 'direct_expense')->value('id'),
        'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-20', 'financial_account_id' => $this->petty->id, 'amount' => '10', 'debit_account_id' => $this->expense->id, ...$this->payee], $clerk))
        ->toThrow(HttpException::class);
    expect(OperationalTransaction::query()->where('type', 'petty_cash')->count())->toBe(0);
});

test('a float can be funded only through Imprest replenishment, not by receipts, donations, fee mappings or manual journals', function () {
    $this->seed(DonationCategorySeeder::class);
    $income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $category = DonationCategory::query()->firstOrFail();
    $category->update(['income_account_id' => $income->id]);
    $receipt = fn (OperationalTransactionType $type, FinancialAccount $account, array $extra = []) => $this->engine->create(['type' => $type->value, 'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-20', 'amount' => '250', 'financial_account_id' => $account->id, 'contra_account_id' => $income->id, 'narration' => 'Funding attempt', ...$extra], $this->maker);

    expect(fn () => $receipt(OperationalTransactionType::CashReceipt, $this->petty))->toThrow(ValidationException::class, 'holds an imprest float')
        ->and(fn () => $receipt(OperationalTransactionType::Donation, $this->petty, ['contra_account_id' => null, 'donation_category_id' => $category->id]))->toThrow(ValidationException::class, 'holds an imprest float')
        ->and($receipt(OperationalTransactionType::CashReceipt, $this->cash)->financial_account_id)->toBe($this->cash->id)
        ->and($receipt(OperationalTransactionType::Donation, $this->cash, ['contra_account_id' => null, 'donation_category_id' => $category->id])->type)->toBe(OperationalTransactionType::Donation)
        ->and(app(FeeSettlementMappingService::class)->accountIssue($this->petty, $this->branch->id, 'cash'))->toContain('holds a petty cash imprest float');

    // A manual journal debiting the float's ledger (a cash-bank transfer in form) is refused at submission.
    $workflow = app(JournalWorkflowService::class);
    $journal = $workflow->create(['voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $this->period->fiscal_year_id, 'accounting_period_id' => $this->period->id, 'branch_id' => $this->branch->id, 'narration' => 'Top up petty cash by JV',
        'lines' => [['account_id' => $this->petty->account_id, 'debit' => '500', 'credit' => '0'], ['account_id' => $this->bank->account_id, 'debit' => '0', 'credit' => '500']]], $this->maker);
    expect(fn () => $workflow->submit($journal, $this->maker))->toThrow(ValidationException::class, 'only be refilled through Imprest replenishment')
        ->and(AuditLog::query()->where('event', 'journal.imprest_funding_blocked')->exists())->toBeTrue()
        ->and(app(ImprestLedgerService::class)->balance(ImprestFloat::query()->firstOrFail())->isEqualTo('1000'))->toBeTrue();
});

test('the payment screen offers each source with its own accounts, float balance and purposes', function () {
    $other = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => Branch::factory()->create(['status' => 'publish']), 'account_id' => Account::factory()]);
    $form = $this->get(route('payments.create'))->assertOk()
        ->assertSee('<option value="bank" >Bank</option>', false)->assertSee('<option value="cash" >Cash</option>', false)->assertSee('<option value="petty_cash" >Petty Cash</option>', false)
        ->assertSee('No funded petty cash float is available for this branch. Set up and fund a float through Petty Cash Management.')
        ->assertDontSee('Cash and petty cash keep their own screens for now.')->assertSee("'Select payee type'", false)->assertSee('id="payment-save"', false)->getContent();

    $option = fn (FinancialAccount $account) => preg_match('/<option value="'.$account->id.'" data-source="([a-z_]+)"[^>]*data-available="([0-9.]*)"/', $form, $m) ? [$m[1], $m[2]] : null;
    expect($option($this->bank))->toBe(['bank', ''])->and($option($this->cash))->toBe(['cash', ''])->and($option($this->petty))->toBe(['petty_cash', '1000.0000'])
        ->and($option($other))->toBe(['cash', ''])->and($form)->toContain('value="'.$other->id.'" data-source="cash" data-branch-id="'.$other->branch_id.'"')->and($form)->toContain('available PKR 1,000.00');

    $purposeSources = fn (string $code) => preg_match('/value="'.PaymentPurpose::query()->where('code', $code)->value('id').'" data-behaviour="[a-z_]+" data-payment-sources="([a-z_,]+)"/', $form, $m) ? $m[1] : null;
    expect($purposeSources('direct_expense'))->toBe('bank,cash,petty_cash');
    foreach (['vendor_bill', 'accrued_payable', 'salary_payable', 'staff_advance'] as $code) {
        expect($purposeSources($code))->toBe('bank,cash');
    }

    // Without petty_cash.create the source is not offered at all.
    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['payments.create']);
    $clerk->branches()->attach($this->branch);
    $this->actingAs($clerk)->get(route('payments.create'))->assertOk()->assertSee('<option value="cash" >Cash</option>', false)->assertDontSee('<option value="petty_cash"', false);
});

test('the payment screen saves cash and petty cash drafts and refuses forged sources, accounts and overspends', function () {
    $post = fn (string $source, FinancialAccount $account, string $amount) => $this->post(route('payments.store'), ['payment_source' => $source,
        'payment_purpose_id' => PaymentPurpose::query()->where('code', 'direct_expense')->value('id'), 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-20',
        'financial_account_id' => $account->id, 'amount' => $amount, 'debit_account_id' => $this->expense->id, 'narration' => 'Screen test', ...$this->payee]);

    $post('cash', $this->cash, '100')->assertSessionHasNoErrors()->assertRedirect();
    $post('petty_cash', $this->petty, '250')->assertSessionHasNoErrors()->assertRedirect();
    expect(OperationalTransaction::query()->pluck('type')->map->value->sort()->values()->all())->toBe(['cash_payment', 'petty_cash']);

    $post('bank', $this->cash, '10')->assertSessionHasErrors('financial_account_id');
    $post('cash', $this->petty, '10')->assertSessionHasErrors('financial_account_id');
    $post('petty_cash', $this->bank, '10')->assertSessionHasErrors('financial_account_id');
    $post('cheque_book', $this->bank, '10')->assertSessionHasErrors('payment_source');
    $post('petty_cash', $this->petty, '1000.01')->assertSessionHasErrors();
    expect(OperationalTransaction::query()->count())->toBe(2);
});
