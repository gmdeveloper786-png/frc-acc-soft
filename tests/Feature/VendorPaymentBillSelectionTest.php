<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branchA = Branch::factory()->create(['status' => 'publish', 'name' => 'Vendor Pay Branch A']);
    $this->branchB = Branch::factory()->create(['status' => 'publish', 'name' => 'Vendor Pay Branch B']);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->bankA = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branchA, 'account_id' => Account::factory()->create(), 'name' => 'Branch A Bank']);
    $this->bankB = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branchB, 'account_id' => Account::factory()->create(), 'name' => 'Branch B Bank']);
    $this->vendorA = Vendor::factory()->create(['name' => 'Vendor Pay Landlord A']);
    $this->vendorB = Vendor::factory()->create(['name' => 'Vendor Pay Supplier B']);
    $this->billA = vendorPayPostedBill($this, $this->branchA, $this->vendorA, 'VP-BILL-A', '500.0000');
    $this->billB = vendorPayPostedBill($this, $this->branchB, $this->vendorB, 'VP-BILL-B', '300.0000');
    $this->actingAs($this->maker);
});

function vendorPayPostedBill(object $test, Branch $branch, Vendor $vendor, string $number, string $amount): PurchaseBill
{
    $service = app(PurchaseBillService::class);
    $test->actingAs($test->maker);
    $bill = $service->create([
        'vendor_id' => $vendor->id, 'branch_id' => $branch->id, 'payable_account_id' => $test->payable->id,
        'bill_number' => $number, 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $test->expense->id, 'description' => 'Vendor payment fixture', 'quantity' => '1.0000', 'unit_amount' => $amount]],
    ], $test->maker);
    $service->submit($bill, $test->maker);
    $test->actingAs($test->checker);
    $service->approve($bill->fresh(), $test->checker);

    return $service->post($bill->fresh(), $test->checker);
}

/** A vendor payment request for $bill that only lacks what the caller overrides. */
function vendorPayPayload(object $test, PurchaseBill $bill, array $overrides = []): array
{
    return [
        'type' => OperationalTransactionType::VendorPayment->value, 'purchase_bill_id' => $bill->id, 'branch_id' => $bill->branch_id,
        'transaction_date' => '2026-01-15', 'amount' => '100.0000', 'financial_account_id' => $bill->branch_id === $test->branchA->id ? $test->bankA->id : $test->bankB->id,
        'narration' => 'Vendor payment selection test', ...$overrides,
    ];
}

test('opening the form with ?bill= preselects the bill and takes branch, vendor and outstanding from it', function () {
    $response = $this->get(route('transactions.create', ['type' => 'vendor_payment', 'bill' => $this->billA->id]))->assertOk();
    $html = $response->getContent();

    expect($html)->toMatch('/<option value="'.$this->billA->id.'"[^>]*selected[^>]*>/')
        ->and($html)->not->toMatch('/<option value="'.$this->billB->id.'"[^>]*selected[^>]*>/')
        ->and($html)->toContain('name="branch_id" value="'.$this->branchA->id.'"')
        ->and($html)->toContain('name="vendor_id" value="'.$this->vendorA->id.'"')
        ->and($html)->toContain('value="Vendor Pay Branch A"')
        ->and($html)->toContain('value="Vendor Pay Landlord A"')
        ->and($html)->toContain('Outstanding: PKR 500.00')
        // Neither the cash/bank account nor the amount is chosen for the user.
        ->and($html)->not->toMatch('/data-account-type="bank"[^>]*selected/')
        ->and($html)->toContain('name="amount" value=""');
});

test('every bill option carries its own branch, vendor and outstanding so changing the bill updates them', function () {
    $html = $this->get(route('transactions.create', ['type' => 'vendor_payment', 'bill' => $this->billB->id]))->assertOk()->getContent();

    expect($html)->toContain('data-branch-id="'.$this->branchA->id.'" data-branch-name="Vendor Pay Branch A" data-vendor-id="'.$this->vendorA->id.'" data-vendor-name="Vendor Pay Landlord A" data-outstanding="500.00"')
        ->and($html)->toContain('data-branch-id="'.$this->branchB->id.'" data-branch-name="Vendor Pay Branch B" data-vendor-id="'.$this->vendorB->id.'" data-vendor-name="Vendor Pay Supplier B" data-outstanding="300.00"')
        ->and($html)->toMatch('/<option value="'.$this->billB->id.'"[^>]*selected[^>]*>/')
        ->and($html)->toContain('name="branch_id" value="'.$this->branchB->id.'"')
        ->and($html)->toContain('Outstanding: PKR 300.00')
        ->and($html)->toContain("bill.addEventListener('change', applyBill)");

    // The changed bill is what gets saved, with its own branch and vendor.
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billB))->assertSessionHasNoErrors();
    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->purchase_bill_id)->toBe($this->billB->id)
        ->and((int) $payment->branch_id)->toBe($this->branchB->id)
        ->and((int) $payment->vendor_id)->toBe($this->vendorB->id);
});

test('an unknown or unpayable bill in ?bill= selects nothing', function () {
    $html = $this->get(route('transactions.create', ['type' => 'vendor_payment', 'bill' => 999999]))->assertOk()->getContent();

    expect($html)->not->toMatch('/<option value="\d+"[^>]*data-branch-id[^>]*selected/')
        ->and($html)->toContain('name="branch_id" value=""')
        ->and($html)->toContain('name="vendor_id" value=""');
});

test('a forged branch or vendor that does not match the bill is rejected and nothing is saved', function () {
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['branch_id' => $this->branchB->id, 'financial_account_id' => $this->bankB->id]))
        ->assertSessionHasErrors('purchase_bill_id');
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['vendor_id' => $this->vendorB->id]))
        ->assertSessionHasErrors('vendor_id');

    expect(OperationalTransaction::query()->count())->toBe(0);
});

test('the vendor is taken from the bill when the request leaves it out', function () {
    $payload = vendorPayPayload($this, $this->billA);
    unset($payload['vendor_id']);

    $this->post(route('transactions.store'), $payload)->assertSessionHasNoErrors();

    expect((int) OperationalTransaction::query()->latest('id')->value('vendor_id'))->toBe($this->vendorA->id);
});

test('a cash or bank account of another branch is rejected for a bill-linked payment', function () {
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['financial_account_id' => $this->bankB->id]))
        ->assertSessionHasErrors('financial_account_id');

    expect(OperationalTransaction::query()->count())->toBe(0);
});

test('the posted status and outstanding amount are checked again when saving', function () {
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['amount' => '500.0100']))->assertSessionHasErrors('amount');

    $draft = app(PurchaseBillService::class)->create([
        'vendor_id' => $this->vendorA->id, 'branch_id' => $this->branchA->id, 'payable_account_id' => $this->payable->id,
        'bill_number' => 'VP-BILL-DRAFT', 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Draft', 'quantity' => '1.0000', 'unit_amount' => '50.0000']],
    ], $this->maker);
    $this->post(route('transactions.store'), vendorPayPayload($this, $draft))->assertSessionHasErrors('purchase_bill_id');

    expect(OperationalTransaction::query()->count())->toBe(0);
});

test('after an unrelated validation error the form keeps the chosen bill and entered values', function () {
    $html = $this->withSession(['_old_input' => [
        'type' => 'vendor_payment', 'purchase_bill_id' => (string) $this->billB->id, 'branch_id' => (string) $this->branchB->id,
        'vendor_id' => (string) $this->vendorB->id, 'financial_account_id' => (string) $this->bankB->id, 'amount' => '120.00', 'narration' => 'Kept narration',
    ]])->get(route('transactions.create', ['type' => 'vendor_payment', 'bill' => $this->billA->id]))->assertOk()->getContent();

    expect($html)->toMatch('/<option value="'.$this->billB->id.'"[^>]*selected[^>]*>/')
        ->and($html)->toContain('name="branch_id" value="'.$this->branchB->id.'"')
        ->and($html)->toContain('name="vendor_id" value="'.$this->vendorB->id.'"')
        ->and($html)->toMatch('/<option value="'.$this->bankB->id.'"[^>]*selected[^>]*>/')
        ->and($html)->toContain('name="amount" value="120.00"')
        ->and($html)->toContain('Kept narration');
});

test('forms that are not linked to a bill keep their branch and vendor selectors', function () {
    $html = $this->get(route('transactions.create', ['type' => 'bank_payment']))->assertOk()->getContent();

    expect($html)->toContain('<select class="form-select" id="transaction-branch" name="branch_id" required>')
        ->and($html)->toContain('Vendor (optional)')
        ->and($html)->not->toContain('id="vendor-payment-bill"')
        ->and($html)->not->toContain('Taken from the purchase bill');

    // An unlinked payment keeps the vendor the user chose and never links a bill.
    $this->post(route('transactions.store'), [
        'type' => OperationalTransactionType::BankPayment->value, 'branch_id' => $this->branchA->id, 'transaction_date' => '2026-01-15',
        'amount' => '25.0000', 'financial_account_id' => $this->bankA->id, 'contra_account_id' => $this->expense->id,
        'vendor_id' => $this->vendorB->id, 'purchase_bill_id' => $this->billA->id, 'narration' => 'Unlinked bank payment',
    ])->assertSessionHasNoErrors();
    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect((int) $payment->vendor_id)->toBe($this->vendorB->id)->and($payment->purchase_bill_id)->toBeNull();
});

test('pay on a purchase bill opens the unified payment screen on what is still free to pay, and the vendor payment rules still apply', function () {
    $this->get(route('purchase-bills.show', $this->billA))->assertOk()->assertSee('href="'.e(route('payments.create', ['bill' => $this->billA->id])).'"', false);
    $journals = JournalEntry::query()->count();
    $prefilled = fn (PurchaseBill $bill) => $this->get(route('payments.create', ['bill' => $bill->id]))->assertOk()->getContent();
    expect($prefilled($this->billA))->toContain('data-prefill-bill="'.$this->billA->id.'"')->toContain('name="amount" value="500.0000"')
        ->toContain('<option value="'.$this->branchA->id.'" selected')
        ->and(preg_match('/<option value="'.$this->vendorA->id.'"[^>]*selected/', $prefilled($this->billA)))->toBe(1)
        ->and(OperationalTransaction::query()->count())->toBe(0);

    $pay = fn (array $overrides = []) => $this->post(route('payments.store'), ['payment_source' => 'bank', 'payment_purpose_id' => PaymentPurpose::query()->where('code', 'vendor_bill')->value('id'),
        'payee_type_id' => PayeeType::query()->where('code', 'vendor')->value('id'), 'payee_vendor_id' => $this->vendorA->id, 'purchase_bill_id' => $this->billA->id,
        'branch_id' => $this->branchA->id, 'transaction_date' => '2026-01-15', 'financial_account_id' => $this->bankA->id, 'amount' => '200', ...$overrides]);
    // A partial payment, then the prefill offers only what is left after it (it reserves the bill while in approval).
    $pay()->assertSessionHasNoErrors()->assertRedirect();
    $payment = OperationalTransaction::query()->sole();
    expect($payment->type)->toBe(OperationalTransactionType::VendorPayment)->and($payment->purchase_bill_id)->toBe($this->billA->id)
        ->and($payment->vendor_id)->toBe($this->vendorA->id)->and($prefilled($this->billA))->toContain('name="amount" value="300.0000"');
    // Its detail shows the bill it pays and the bill's payable ledger it debits (a vendor payment stores no contra of its own).
    expect($payment->contra_account_id)->toBeNull();
    $this->get(route('transactions.show', $payment))->assertOk()->assertSee('Linked purchase bill')
        ->assertSee('href="'.route('purchase-bills.show', $this->billA).'"', false)->assertSee('VP-BILL-A')
        ->assertSee($this->payable->code.' '.$this->payable->name)->assertSee('Payable ledger of the purchase bill');

    // Tampered amount, vendor, branch and bill are refused; nothing is created and nothing is journalled.
    $pay(['amount' => '300.01'])->assertSessionHasErrors();
    $pay(['payee_vendor_id' => $this->vendorB->id])->assertSessionHasErrors();
    $pay(['purchase_bill_id' => $this->billB->id])->assertSessionHasErrors();
    $pay(['branch_id' => $this->branchB->id, 'financial_account_id' => $this->bankB->id])->assertSessionHasErrors();
    expect(OperationalTransaction::query()->count())->toBe(1);

    // Once the rest is committed, the bill is no longer offered for payment.
    $pay(['amount' => '300'])->assertSessionHasNoErrors();
    expect($prefilled($this->billA))->toContain('data-prefill-bill=""')->toContain('name="amount" value=""')
        ->and(JournalEntry::query()->count())->toBe($journals);

    // A user of branch B is not prefilled with branch A's bill and cannot pay it.
    $clerk = User::factory()->create();
    $clerk->assignRole(SystemRole::Accountant->value);
    $clerk->branches()->attach($this->branchB);
    $this->actingAs($clerk)->get(route('payments.create', ['bill' => $this->billA->id]))->assertOk()->assertSee('data-prefill-bill=""', false);
    expect(OperationalTransaction::query()->count())->toBe(2);
});

test('the bill offers a payment only while part of it is free, and tampered or cross-branch bill payments create nothing', function () {
    $engine = app(OperationalTransactionService::class);
    $journals = JournalEntry::query()->count();
    $payLink = 'href="'.e(route('payments.create', ['bill' => $this->billA->id])).'"';

    // Cross-branch: a branch B user cannot pay branch A's bill through either screen, whatever branch is sent.
    $clerk = User::factory()->create();
    $clerk->assignRole(SystemRole::Accountant->value);
    $clerk->branches()->attach($this->branchB);
    $this->actingAs($clerk);
    $unified = ['payment_source' => 'bank', 'payment_purpose_id' => PaymentPurpose::query()->where('code', 'vendor_bill')->value('id'), 'payee_type_id' => PayeeType::query()->where('code', 'vendor')->value('id'),
        'payee_vendor_id' => $this->vendorA->id, 'purchase_bill_id' => $this->billA->id, 'transaction_date' => '2026-01-15', 'amount' => '100'];
    $this->post(route('payments.store'), [...$unified, 'branch_id' => $this->branchA->id, 'financial_account_id' => $this->bankA->id]);
    $this->post(route('payments.store'), [...$unified, 'branch_id' => $this->branchB->id, 'financial_account_id' => $this->bankB->id]);
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['branch_id' => $this->branchB->id, 'financial_account_id' => $this->bankB->id]));
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA));
    expect(OperationalTransaction::query()->count())->toBe(0);

    // Tampered vendor, payable ledger, bill branch and amount are refused for an authorized user too.
    $this->actingAs($this->maker);
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['vendor_id' => $this->vendorB->id]))->assertSessionHasErrors('vendor_id');
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['contra_account_id' => $this->expense->id]))->assertSessionHasErrors('contra_account_id');
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['branch_id' => $this->branchB->id, 'financial_account_id' => $this->bankB->id]))->assertSessionHasErrors('purchase_bill_id');
    $this->post(route('transactions.store'), vendorPayPayload($this, $this->billA, ['amount' => '500.0001']))->assertSessionHasErrors('amount');
    expect(OperationalTransaction::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe($journals);

    // Valid payments still work; once they hold the whole bill, the bill explains instead of offering a payment.
    $first = $engine->create(vendorPayPayload($this, $this->billA, ['amount' => '300']), $this->maker);
    $this->get(route('purchase-bills.show', $this->billA))->assertSee($payLink, false)->assertDontSee('data-bill-fully-reserved', false);
    $engine->create(vendorPayPayload($this, $this->billA, ['amount' => '200', 'contra_account_id' => $this->payable->id]), $this->maker);
    expect($this->billA->fresh()->availableToPayAmount())->toBe('0.0000');
    $this->get(route('purchase-bills.show', $this->billA))->assertDontSee($payLink, false)->assertSee('already held by payments awaiting approval');
    expect(fn () => $engine->create(vendorPayPayload($this, $this->billA, ['amount' => '0.01']), $this->maker))->toThrow(ValidationException::class, 'exceeds');

    // Returning a payment releases its share, and the bill can be paid again for that much.
    $engine->submit($first, $this->maker);
    $engine->reject($first->fresh(), 'Wrong bank account chosen', $this->checker);
    expect($this->billA->fresh()->availableToPayAmount())->toBe('300.0000');
    $this->get(route('purchase-bills.show', $this->billA))->assertSee($payLink, false);
    expect(JournalEntry::query()->count())->toBe($journals);
});

test('a payment returned for correction releases its share, but resubmitting it is re-checked, and a reversal releases a posted share', function () {
    $engine = app(OperationalTransactionService::class);
    $returned = $engine->create(vendorPayPayload($this, $this->billA, ['amount' => '500']), $this->maker);
    $engine->submit($returned, $this->maker);
    expect($this->billA->fresh()->availableToPayAmount())->toBe('0.0000');

    // "Return for correction" is the reject action: the payment becomes rejected and stops holding the bill.
    $engine->reject($returned->fresh(), 'Attach the vendor invoice', $this->checker);
    expect($returned->fresh()->status)->toBe(OperationalTransactionStatus::Rejected)->and($this->billA->fresh()->availableToPayAmount())->toBe('500.0000');

    // If another payment takes that share meanwhile, the returned one cannot be resubmitted, so the bill is never paid twice.
    $replacement = $engine->create(vendorPayPayload($this, $this->billA, ['amount' => '500']), $this->maker);
    expect(fn () => $engine->submit($returned->fresh(), $this->maker))->toThrow(ValidationException::class, 'exceeds')
        ->and($returned->fresh()->status)->toBe(OperationalTransactionStatus::Rejected);

    // The replacement is paid and posted; reversing it releases the bill again and the returned payment may then go ahead.
    $engine->submit($replacement, $this->maker);
    $engine->approve($replacement->fresh(), $this->checker);
    $engine->disburse($replacement->fresh(), $this->checker);
    $posted = $engine->post($replacement->fresh(), $this->checker);
    expect($this->billA->fresh()->availableToPayAmount())->toBe('0.0000')->and($this->billA->fresh()->outstandingAmount())->toBe('0.0000');
    $engine->reverse($posted->fresh(), AccountingPeriod::query()->firstOrFail(), '2026-01-25', 'Paid from the wrong bank', $this->checker);
    expect($this->billA->fresh()->availableToPayAmount())->toBe('500.0000')->and($this->billA->fresh()->outstandingAmount())->toBe('500.0000');
    // The reversing journal swaps the payment's lines: the bank is debited back and the bill's payable credited again.
    $reversal = JournalEntry::query()->where('reversal_of_id', $posted->journal_entry_id)->sole();
    $lines = fn (JournalEntry $journal) => $journal->lines()->get()->map(fn ($line) => [$line->account_id, (string) $line->debit, (string) $line->credit])->sortBy(0)->values()->all();
    expect($lines($reversal))->toBe(collect($lines($posted->fresh()->journalEntry))->map(fn ($line) => [$line[0], $line[2], $line[1]])->all())
        ->and(fn () => $engine->reverse($posted->fresh(), AccountingPeriod::query()->firstOrFail(), '2026-01-26', 'Second reversal', $this->checker))->toThrow(ValidationException::class, 'Only a posted transaction');
    $this->get(route('purchase-bills.show', $this->billA))->assertSee('href="'.e(route('payments.create', ['bill' => $this->billA->id])).'"', false);
    $this->get(route('payments.create', ['bill' => $this->billA->id]))->assertSee('name="amount" value="500.0000"', false);
    expect($engine->submit($returned->fresh(), $this->maker)->status)->toBe(OperationalTransactionStatus::Submitted);
});
