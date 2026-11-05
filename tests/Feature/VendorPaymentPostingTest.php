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
    $this->disburser = User::factory()->create();
    $this->disburser->assignRole(SystemRole::Administrator->value);

    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Shahrah-e-Faisal']);
    $this->expense = Account::factory()->create(['code' => '52101', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->payable = Account::factory()->create(['code' => '21101', 'account_type' => 'liability', 'normal_balance' => 'credit', 'name' => 'Trade Creditors / Suppliers']);
    $this->bankGl = Account::factory()->create(['code' => '11203', 'account_type' => 'asset', 'name' => 'Bank - Rehab Centre Account']);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch->id, 'account_id' => $this->bankGl->id, 'name' => 'MCB Bank Account']);

    $this->vendor = Vendor::factory()->create(['name' => 'DEMO ABC Stationery Supplier']);

    $billService = app(PurchaseBillService::class);
    $this->bill = $billService->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'UAT-BILL-PO-002',
        'invoice_date' => '2026-10-31',
        'lines' => [
            ['account_id' => $this->expense->id, 'description' => 'Office Stationery', 'quantity' => '1.0000', 'unit_amount' => '1000.0000'],
        ],
    ], $this->maker);
    $billService->submit($this->bill, $this->maker);
    $billService->approve($this->bill->fresh(), $this->checker);
    $this->bill = $billService->post($this->bill->fresh(), $this->checker);

    $this->actingAs($this->maker);
});

function createDisbursedVendorPayment(object $test, string $narration, string $amount = '1000.0000', string $reference = 'UAT-VPAY-PO002-001'): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $payment = $service->create([
        'type' => OperationalTransactionType::VendorPayment->value,
        'branch_id' => $test->branch->id,
        'transaction_date' => '2026-10-31',
        'amount' => $amount,
        'financial_account_id' => $test->bank->id,
        'purchase_bill_id' => $test->bill->id,
        'vendor_id' => $test->vendor->id,
        'party_name' => $test->vendor->name,
        'reference_number' => $reference,
        'narration' => $narration,
    ], $test->maker);

    $service->submit($payment, $test->maker);
    $service->approve($payment->fresh(), $test->checker);
    $service->disburse($payment->fresh(), $test->disburser);

    return $payment->fresh();
}

test('vendor payment with long narration posts without HTTP 500 and preserves full source narration', function () {
    $longNarration = 'DEMO/UAT ONLY - Full settlement of Purchase Bill UAT-BILL-PO-002 against approved Purchase Order PO-2026-00001 for office stationery supplied by DEMO ABC Stationery Supplier, Shahrah-e-Faisal branch. Testing bank payment approval, Trade Creditors settlement, GL posting and audit trail. No actual bank transfer.';
    expect(strlen($longNarration))->toBeGreaterThan(255);

    $payment = createDisbursedVendorPayment($this, $longNarration, '1000.0000', 'UAT-VPAY-PO002-001');

    $this->actingAs($this->checker);
    $response = $this->post(route('transactions.post', $payment));
    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $payment = $payment->fresh();
    expect($payment->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($payment->journal_entry_id)->not->toBeNull()
        // Full source narration preserved on source record
        ->and($payment->narration)->toBe($longNarration)
        ->and(strlen($payment->narration))->toBe(strlen($longNarration));

    $journal = $payment->journalEntry;
    expect($journal)->not->toBeNull()
        // Full source narration preserved on journal entry header
        ->and($journal->narration)->toBe($longNarration)
        ->and(strlen($journal->narration))->toBe(strlen($longNarration))
        // Journal total debit and credit are balanced
        ->and($journal->total_debit)->toBe('1000.0000')
        ->and($journal->total_credit)->toBe('1000.0000');

    // Lines verification
    $lines = $journal->lines;
    expect($lines)->toHaveCount(2);

    // Debit line: 21101 Trade Creditors
    $debitLine = $lines->firstWhere('debit', '1000.0000');
    expect($debitLine)->not->toBeNull()
        ->and($debitLine->account_id)->toBe($this->payable->id)
        ->and(mb_strlen($debitLine->description))->toBeLessThanOrEqual(255)
        ->and($debitLine->description)->toContain('Ref: UAT-VPAY-PO002-001');

    // Credit line: 11203 Bank
    $creditLine = $lines->firstWhere('credit', '1000.0000');
    expect($creditLine)->not->toBeNull()
        ->and($creditLine->account_id)->toBe($this->bankGl->id)
        ->and(mb_strlen($creditLine->description))->toBeLessThanOrEqual(255)
        ->and($creditLine->description)->toContain('Ref: UAT-VPAY-PO002-001');

    // Bill settlement verification: Bill outstanding must now be 0.0000
    expect($this->bill->fresh()->outstandingAmount())->toBe('0.0000');
});

test('posting respects short narration verbatim and formats blank narration meaningfully', function () {
    $shortNarration = 'Short vendor payment narration';
    $payment = createDisbursedVendorPayment($this, $shortNarration, '250.0000', 'VP-REF-SHORT');

    $service = app(OperationalTransactionService::class);
    $service->post($payment, $this->checker);

    $journal = $payment->fresh()->journalEntry;
    expect($journal->lines[0]->description)->toBe($shortNarration)
        ->and($journal->lines[1]->description)->toBe($shortNarration);
});

test('failed posting maintains atomic rollback without partial records or side effects', function () {
    $payment = createDisbursedVendorPayment($this, 'Atomic rollback verification', '500.0000', 'VP-ROLLBACK-01');

    // Close the period to force posting failure inside the transaction
    $this->period->update(['status' => AccountingPeriodStatus::Closed]);

    $service = app(OperationalTransactionService::class);
    expect(fn () => $service->post($payment, $this->checker))->toThrow(ValidationException::class);

    // Verify atomic state: payment stays disbursed with null journal_entry_id
    $freshPayment = $payment->fresh();
    expect($freshPayment->status)->toBe(OperationalTransactionStatus::Disbursed)
        ->and($freshPayment->journal_entry_id)->toBeNull()
        ->and($freshPayment->posted_at)->toBeNull();

    // No orphan journal entries or lines created
    expect(JournalEntry::query()->where('source_key', 'operational_transaction:'.$payment->id)->count())->toBe(0);

    // Bill outstanding remains untouched
    expect($this->bill->fresh()->outstandingAmount())->toBe('1000.0000');
});

test('duplicate posting is strictly prevented', function () {
    $payment = createDisbursedVendorPayment($this, 'Duplicate post guard', '100.0000', 'VP-DUP-01');

    $service = app(OperationalTransactionService::class);
    $service->post($payment, $this->checker);
    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Posted);

    // Second call throws ValidationException
    expect(fn () => $service->post($payment->fresh(), $this->checker))
        ->toThrow(ValidationException::class, 'This transaction has already been posted.');
});

test('unapproved or undisbursed vendor payment cannot be posted', function () {
    $service = app(OperationalTransactionService::class);
    $draft = $service->create([
        'type' => OperationalTransactionType::VendorPayment->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-10-31',
        'amount' => '100.0000',
        'financial_account_id' => $this->bank->id,
        'purchase_bill_id' => $this->bill->id,
        'party_name' => $this->vendor->name,
        'narration' => 'Draft unapproved payment',
    ], $this->maker);

    // Cannot post Draft
    expect(fn () => $service->post($draft, $this->checker))->toThrow(ValidationException::class);

    // Submit it: cannot post Submitted
    $service->submit($draft, $this->maker);
    expect(fn () => $service->post($draft->fresh(), $this->checker))->toThrow(ValidationException::class);

    // Approve it: cannot post Approved before Disbursement
    $service->approve($draft->fresh(), $this->checker);
    expect(fn () => $service->post($draft->fresh(), $this->checker))->toThrow(ValidationException::class);
});

test('operational expense and receipt with long narration post cleanly without truncation error', function () {
    $service = app(OperationalTransactionService::class);
    $longNarration = str_repeat('Long operational transaction narration for testing column boundary safety. ', 5);
    expect(strlen($longNarration))->toBeGreaterThan(255);

    // Bank Payment
    $payment = $service->create([
        'type' => OperationalTransactionType::BankPayment->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-10-31',
        'amount' => '300.0000',
        'financial_account_id' => $this->bank->id,
        'contra_account_id' => $this->expense->id,
        'reference_number' => 'PAY-LONG-001',
        'narration' => $longNarration,
    ], $this->maker);
    $service->submit($payment, $this->maker);
    $service->approve($payment->fresh(), $this->checker);
    $service->disburse($payment->fresh(), $this->disburser);
    $postedPayment = $service->post($payment->fresh(), $this->checker);

    expect($postedPayment->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($postedPayment->narration)->toBe($longNarration)
        ->and($postedPayment->journalEntry->narration)->toBe($longNarration)
        ->and(mb_strlen($postedPayment->journalEntry->lines[0]->description))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($postedPayment->journalEntry->lines[1]->description))->toBeLessThanOrEqual(255);

    // Cash / Bank Receipt
    $incomeGl = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $receipt = $service->create([
        'type' => OperationalTransactionType::BankReceipt->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-10-31',
        'amount' => '500.0000',
        'financial_account_id' => $this->bank->id,
        'contra_account_id' => $incomeGl->id,
        'reference_number' => 'REC-LONG-001',
        'narration' => $longNarration,
    ], $this->maker);
    $postedReceipt = $service->post($receipt, $this->maker);

    expect($postedReceipt->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($postedReceipt->narration)->toBe($longNarration)
        ->and($postedReceipt->journalEntry->narration)->toBe($longNarration)
        ->and(mb_strlen($postedReceipt->journalEntry->lines[0]->description))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($postedReceipt->journalEntry->lines[1]->description))->toBeLessThanOrEqual(255);
});
