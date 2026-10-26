<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()->create()]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()->create()]);
    $this->service = app(OperationalTransactionService::class);
    $this->actingAs($this->maker);
});

/** A draft of $type with everything it needs to be posted once its workflow is complete. */
function postButtonDraft(object $test, OperationalTransactionType $type, array $overrides = []): OperationalTransaction
{
    $data = [
        'type' => $type->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-15', 'party_name' => 'Post button party',
        'amount' => '100.0000', 'financial_account_id' => $type === OperationalTransactionType::BankPayment || $type === OperationalTransactionType::BankReceipt ? $test->bank->id : $test->cash->id,
        'contra_account_id' => in_array($type, [OperationalTransactionType::CashReceipt, OperationalTransactionType::BankReceipt], true) ? $test->income->id : $test->expense->id,
        'narration' => 'Post button visibility fixture', ...$overrides,
    ];
    $test->actingAs($test->maker);

    return $test->service->create($data, $test->maker);
}

function postButtonVendorPaymentDraft(object $test): OperationalTransaction
{
    $bills = app(PurchaseBillService::class);
    $bill = $bills->create([
        'vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $test->branch->id, 'payable_account_id' => $test->payable->id,
        'bill_number' => 'PB-POSTBTN-'.uniqid(), 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $test->expense->id, 'description' => 'Post button bill', 'quantity' => '1.0000', 'unit_amount' => '500.0000']],
    ], $test->maker);
    $bills->submit($bill, $test->maker);
    $bills->approve($bill->fresh(), $test->checker);
    $bill = $bills->post($bill->fresh(), $test->checker);

    return postButtonDraft($test, OperationalTransactionType::VendorPayment, ['purchase_bill_id' => $bill->id, 'contra_account_id' => null, 'financial_account_id' => $test->bank->id]);
}

/** Whether the detail page offers "Post to Journal Engine" to $user. */
function postButtonShown(object $test, OperationalTransaction $transaction, ?User $user = null): bool
{
    return str_contains($test->actingAs($user ?? $test->checker)->get(route('transactions.show', $transaction->fresh()))->assertOk()->getContent(), 'Post to Journal Engine');
}

/** A direct POST to the post route is refused and leaves the transaction unposted. */
function expectDirectPostRefused(object $test, OperationalTransaction $transaction): void
{
    $status = $transaction->fresh()->status;
    $test->actingAs($test->checker)->post(route('transactions.post', $transaction))->assertSessionHasErrors('status');
    expect($transaction->fresh()->status)->toBe($status)
        ->and($transaction->fresh()->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('source_type', $transaction->type->value)->where('source_id', (string) $transaction->id)->exists())->toBeFalse();
}

/**
 * Walk a payment that needs approval and disbursement through its workflow and check
 * that Post is offered only once it is disbursed, and refused before that.
 */
function expectPostOnlyWhenDisbursed(object $test, OperationalTransaction $transaction): void
{
    expect(postButtonShown($test, $transaction))->toBeFalse()
        ->and($test->service->postableStatus($transaction))->toBe(OperationalTransactionStatus::Disbursed);
    expectDirectPostRefused($test, $transaction);

    $test->service->submit($transaction->fresh(), $test->maker);
    expect(postButtonShown($test, $transaction))->toBeFalse();
    expectDirectPostRefused($test, $transaction);

    $test->service->approve($transaction->fresh(), $test->checker);
    expect(postButtonShown($test, $transaction))->toBeFalse();
    expectDirectPostRefused($test, $transaction);

    $test->service->disburse($transaction->fresh(), $test->checker);
    expect(postButtonShown($test, $transaction))->toBeTrue()
        ->and($test->service->canPost($transaction->fresh(), $test->checker))->toBeTrue();

    $test->actingAs($test->checker)->post(route('transactions.post', $transaction))->assertSessionHasNoErrors();
    expect($transaction->fresh()->status)->toBe(OperationalTransactionStatus::Posted)
        ->and(postButtonShown($test, $transaction))->toBeFalse()
        ->and($test->service->canPost($transaction->fresh(), $test->checker))->toBeFalse();
}

test('a draft vendor payment shows no post button and cannot be posted by a direct request', function () {
    $payment = postButtonVendorPaymentDraft($this);

    expect(postButtonShown($this, $payment))->toBeFalse()
        ->and(postButtonShown($this, $payment, $this->maker))->toBeFalse()
        ->and($this->service->canPost($payment, $this->checker))->toBeFalse();
    expectDirectPostRefused($this, $payment);
});

test('a vendor payment is postable only after approval and disbursement', function () {
    expectPostOnlyWhenDisbursed($this, postButtonVendorPaymentDraft($this));
});

test('a petty cash voucher is postable only after approval and disbursement', function () {
    expectPostOnlyWhenDisbursed($this, postButtonDraft($this, OperationalTransactionType::PettyCash, ['financial_account_id' => fundedPettyCashAccount($this->branch->id)->id]));
});

test('a paid expense is postable only after approval and disbursement', function () {
    expectPostOnlyWhenDisbursed($this, postButtonDraft($this, OperationalTransactionType::Expense, ['settlement_type' => 'paid', 'payable_account_id' => $this->payable->id]));
});

test('cash and bank payments are postable only after approval and disbursement', function () {
    expectPostOnlyWhenDisbursed($this, postButtonDraft($this, OperationalTransactionType::CashPayment));
    expectPostOnlyWhenDisbursed($this, postButtonDraft($this, OperationalTransactionType::BankPayment));
});

test('an accrued expense is postable once approved, without disbursement', function () {
    $expense = postButtonDraft($this, OperationalTransactionType::Expense, ['settlement_type' => 'accrued', 'financial_account_id' => null, 'payable_account_id' => $this->payable->id]);

    expect($this->service->postableStatus($expense))->toBe(OperationalTransactionStatus::Approved)
        ->and(postButtonShown($this, $expense))->toBeFalse();
    expectDirectPostRefused($this, $expense);

    $this->service->submit($expense->fresh(), $this->maker);
    expect(postButtonShown($this, $expense))->toBeFalse();

    $this->service->approve($expense->fresh(), $this->checker);
    expect(postButtonShown($this, $expense))->toBeTrue()
        ->and(str_contains($this->actingAs($this->checker)->get(route('transactions.show', $expense->fresh()))->getContent(), 'Confirm disbursement'))->toBeFalse();

    $this->service->post($expense->fresh(), $this->checker);
    expect(postButtonShown($this, $expense))->toBeFalse();
});

test('receipts and donations need no approval and are postable from draft', function () {
    DonationCategory::query()->firstOrFail()->update(['income_account_id' => $this->income->id]);
    $drafts = [
        postButtonDraft($this, OperationalTransactionType::CashReceipt),
        postButtonDraft($this, OperationalTransactionType::BankReceipt),
        postButtonDraft($this, OperationalTransactionType::Donation, ['contra_account_id' => null, 'donation_category_id' => DonationCategory::query()->firstOrFail()->id]),
    ];

    foreach ($drafts as $draft) {
        expect($this->service->postableStatus($draft))->toBe(OperationalTransactionStatus::Draft)
            ->and(postButtonShown($this, $draft))->toBeTrue();
        $this->actingAs($this->checker)->post(route('transactions.post', $draft))->assertSessionHasNoErrors();
        expect($draft->fresh()->status)->toBe(OperationalTransactionStatus::Posted)
            ->and(postButtonShown($this, $draft))->toBeFalse();
    }
});

test('the post button needs the post permission and access to the branch', function () {
    $payment = postButtonDraft($this, OperationalTransactionType::BankPayment);
    $this->service->submit($payment, $this->maker);
    $this->service->approve($payment->fresh(), $this->checker);
    $this->service->disburse($payment->fresh(), $this->checker);

    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $approver->branches()->attach($this->branch);
    $outsider = User::factory()->create();
    $outsider->assignRole(SystemRole::Accountant->value);
    $outsider->branches()->attach(Branch::factory()->create(['status' => 'publish']));

    expect($approver->can('payments.post'))->toBeFalse()
        ->and($this->service->canPost($payment->fresh(), $approver))->toBeFalse()
        ->and($outsider->can('payments.post'))->toBeTrue()
        ->and($this->service->canPost($payment->fresh(), $outsider))->toBeFalse()
        ->and($this->service->canPost($payment->fresh(), $this->checker))->toBeTrue();
    $this->actingAs($approver)->post(route('transactions.post', $payment))->assertForbidden();
    $this->actingAs($outsider)->post(route('transactions.post', $payment))->assertForbidden();
    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Disbursed);
});

test('a submitted petty cash voucher tells its maker it awaits an approver, while an authorized approver sees approve and return', function () {
    $voucher = postButtonDraft($this, OperationalTransactionType::PettyCash, ['financial_account_id' => fundedPettyCashAccount($this->branch->id)->id]);
    $this->service->submit($voucher, $this->maker);
    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $approver->branches()->attach($this->branch);

    $this->actingAs($this->maker)->get(route('transactions.show', $voucher))->assertOk()
        ->assertSee('Submitted for approval. Awaiting action by an authorized approver.')->assertDontSee('Approve this transaction?')->assertDontSee('Return for correction');
    $this->actingAs($approver)->get(route('transactions.show', $voucher))->assertOk()
        ->assertSee('Approve this transaction?')->assertSee('Return for correction')->assertDontSee('data-workflow-next-step', false);

    $this->service->approve($voucher->fresh(), $approver);
    $this->actingAs($approver)->get(route('transactions.show', $voucher))->assertOk()->assertSee('Approved. Awaiting disbursement or posting by an authorized user.');
    expect($voucher->fresh()->status)->toBe(OperationalTransactionStatus::Approved);
});
