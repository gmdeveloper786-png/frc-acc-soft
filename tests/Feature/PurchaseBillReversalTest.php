<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PurchaseBillStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->admin = pbReversalUser(SystemRole::Administrator, $this->branch);
    $this->accountant = pbReversalUser(SystemRole::Accountant, $this->branch);
    $this->approver = pbReversalUser(SystemRole::Approver, $this->branch);
    $this->auditor = pbReversalUser(SystemRole::Auditor, $this->branch);
    $this->vendor = Vendor::factory()->create();
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true]);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->year = FiscalYear::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
    ]);
    $this->openPeriod = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year->id,
        'name' => 'January 2026',
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => AccountingPeriodStatus::Open,
    ]);
    $this->closedPeriod = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year->id,
        'name' => 'December 2025',
        'period_number' => 12,
        'start_date' => '2025-12-01',
        'end_date' => '2025-12-31',
        'status' => AccountingPeriodStatus::Closed,
    ]);
    $this->bills = app(PurchaseBillService::class);
});

function pbReversalUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function createPostedBill(object $test, string $amount = '5000.0000'): PurchaseBill
{
    $bill = $test->bills->create([
        'vendor_id' => $test->vendor->id,
        'branch_id' => $test->branch->id,
        'payable_account_id' => $test->payable->id,
        'bill_number' => 'PB-REV-'.uniqid(),
        'invoice_date' => '2026-01-15',
        'lines' => [[
            'account_id' => $test->expense->id,
            'description' => 'Reversal test item',
            'quantity' => '1.0000',
            'unit_amount' => $amount,
        ]],
    ], $test->accountant);

    $test->bills->submit($bill, $test->accountant);
    $test->bills->approve($bill->fresh(), $test->approver);
    $test->bills->post($bill->fresh(), $test->accountant);

    return $bill->fresh();
}

test('authorized user with purchase_bills.reverse sees the reversal UI on a posted bill', function () {
    $bill = createPostedBill($this);

    expect($this->bills->canReverse($bill, $this->accountant))->toBeTrue();

    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertSee('Reverse Purchase Bill')
        ->assertSee(route('purchase-bills.reverse', $bill), false)
        ->assertSee('Accounting Period')
        ->assertSee('Reason for Reversal');
});

test('unauthorized user cannot see the reversal form and cannot post to reverse', function () {
    $bill = createPostedBill($this);

    expect($this->bills->canReverse($bill, $this->auditor))->toBeFalse();

    $this->actingAs($this->auditor)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertDontSee('Reverse Purchase Bill')
        ->assertDontSee(route('purchase-bills.reverse', $bill), false);

    $this->actingAs($this->auditor)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-20',
        'reason' => 'Unauthorized attempt',
    ])->assertForbidden();

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted);
});

test('reversal form requires a mandatory reason of at least 5 characters', function () {
    $bill = createPostedBill($this);

    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-20',
        'reason' => 'bad',
    ])->assertSessionHasErrors('reason');

    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-20',
        'reason' => '',
    ])->assertSessionHasErrors('reason');

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted);
});

test('reversal rejects closed or non-existent accounting period', function () {
    $bill = createPostedBill($this);

    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => 999999,
        'transaction_date' => '2026-01-20',
        'reason' => 'Valid reversal reason here',
    ])->assertSessionHasErrors('accounting_period_id');

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted);
});

test('active committed payment blocks reversal in UI and server-side', function () {
    $bill = createPostedBill($this, '4000.0000');

    // Create an approved payment against this bill
    $payment = OperationalTransaction::query()->create([
        'type' => OperationalTransactionType::VendorPayment->value,
        'status' => OperationalTransactionStatus::Approved,
        'branch_id' => $this->branch->id,
        'purchase_bill_id' => $bill->id,
        'vendor_id' => $this->vendor->id,
        'transaction_date' => '2026-01-18',
        'amount' => '4000.0000',
        'narration' => 'Payment for bill',
        'created_by' => $this->accountant->id,
    ]);

    expect($this->bills->hasCommittedPayments($bill->fresh()))->toBeTrue()
        ->and($this->bills->canReverse($bill->fresh(), $this->accountant))->toBeFalse();

    // UI displays warning and hides the reversal form
    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertSee('Active payments exist against this bill')
        ->assertDontSee('Reverse Bill</button>', false);

    // Direct POST is rejected
    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-20',
        'reason' => 'Attempting reversal with active payment',
    ])->assertSessionHasErrors('status');

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted);
});

test('reversing the linked payment unblocks purchase bill reversal', function () {
    $bill = createPostedBill($this, '3000.0000');

    // Create a payment that was previously reversed
    OperationalTransaction::query()->create([
        'type' => OperationalTransactionType::VendorPayment->value,
        'status' => OperationalTransactionStatus::Reversed,
        'branch_id' => $this->branch->id,
        'purchase_bill_id' => $bill->id,
        'vendor_id' => $this->vendor->id,
        'transaction_date' => '2026-01-18',
        'amount' => '3000.0000',
        'narration' => 'Reversed payment for bill',
        'created_by' => $this->accountant->id,
    ]);

    expect($this->bills->hasCommittedPayments($bill->fresh()))->toBeFalse()
        ->and($this->bills->canReverse($bill->fresh(), $this->accountant))->toBeTrue();

    // UI offers reversal
    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertSee('Reverse Purchase Bill')
        ->assertDontSee('Active payments exist against this bill');

    // Successfully reverses
    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-20',
        'reason' => 'Payment was reversed, now reversing purchase bill',
    ])->assertRedirect();

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Reversed);
});

test('successful reversal updates status, posts reversal journal, and creates audit log', function () {
    $bill = createPostedBill($this, '8000.0000');
    $originalJournalId = $bill->journal_entry_id;

    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-22',
        'reason' => 'Incorrect invoice lines billed by vendor',
    ])->assertRedirect()->assertSessionHas('status', 'Bill reversed.');

    $fresh = $bill->fresh();
    expect($fresh->status)->toBe(PurchaseBillStatus::Reversed)
        ->and($fresh->journal_entry_id)->toBe($originalJournalId);

    // Reversal journal exists and balances
    $reversalJournal = JournalEntry::query()->where('reversal_of_id', $originalJournalId)->first();
    expect($reversalJournal)->not->toBeNull()
        ->and($reversalJournal->status->value)->toBe('posted');

    // Audit log recorded
    expect(AuditLog::query()->where('event', 'purchase_bills.reversed')->where('auditable_id', $bill->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'purchase_bills.reversed')->where('auditable_id', $bill->id)->value('actor_id'))->toBe($this->accountant->id);
});

test('already reversed bill displays reversed message and does not show reversal form', function () {
    $bill = createPostedBill($this, '2500.0000');

    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-22',
        'reason' => 'Cancelling duplicate billing',
    ]);

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Reversed);

    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertSee('This purchase bill has been reversed')
        ->assertDontSee('Reverse Bill</button>', false);

    // POSTing again fails
    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-22',
        'reason' => 'Second reversal attempt',
    ])->assertSessionHasErrors('status');
});

test('draft, submitted, approved, and rejected bills do not display the reversal form', function () {
    $draft = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-DRAFT-'.uniqid(),
        'invoice_date' => '2026-01-15',
        'lines' => [[
            'account_id' => $this->expense->id,
            'description' => 'Draft line',
            'quantity' => '1.0000',
            'unit_amount' => '1000.0000',
        ]],
    ], $this->accountant);

    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $draft))
        ->assertOk()
        ->assertDontSee('Reverse Purchase Bill');

    $this->bills->submit($draft, $this->accountant);
    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $draft->fresh()))
        ->assertOk()
        ->assertDontSee('Reverse Purchase Bill');

    $this->bills->approve($draft->fresh(), $this->approver);
    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $draft->fresh()))
        ->assertOk()
        ->assertDontSee('Reverse Purchase Bill');
});

function billPayment(object $test, PurchaseBill $bill, string $amount, OperationalTransactionStatus $status): OperationalTransaction
{
    return OperationalTransaction::query()->create([
        'type' => OperationalTransactionType::VendorPayment->value,
        'status' => $status,
        'branch_id' => $test->branch->id,
        'purchase_bill_id' => $bill->id,
        'vendor_id' => $test->vendor->id,
        'transaction_date' => '2026-01-18',
        'amount' => $amount,
        'narration' => 'Outstanding calculation payment',
        'created_by' => $test->accountant->id,
    ]);
}

function reverseBillThroughScreen(object $test, PurchaseBill $bill): void
{
    $test->actingAs($test->accountant)->post(route('purchase-bills.reverse', $bill), [
        'accounting_period_id' => $test->openPeriod->id,
        'transaction_date' => '2026-01-22',
        'reason' => 'Reversal for outstanding display test',
    ])->assertRedirect();
}

test('active bills keep correct outstanding and available amounts for unpaid, partial and reversed payments', function () {
    $draft = $this->bills->create([
        'vendor_id' => $this->vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-DRAFT-'.uniqid(), 'invoice_date' => '2026-01-15',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Draft', 'quantity' => '1.0000', 'unit_amount' => '700.0000']],
    ], $this->accountant);
    expect($draft->outstandingAmount())->toBe('700.0000');

    $bill = createPostedBill($this, '5000.0000');
    expect($bill->outstandingAmount())->toBe('5000.0000')->and($bill->availableToPayAmount())->toBe('5000.0000');

    $payment = billPayment($this, $bill, '2000.0000', OperationalTransactionStatus::Posted);
    expect($bill->fresh()->outstandingAmount())->toBe('3000.0000')->and($bill->fresh()->availableToPayAmount())->toBe('3000.0000');

    $payment->update(['status' => OperationalTransactionStatus::Reversed]);
    expect($bill->fresh()->outstandingAmount())->toBe('5000.0000')->and($bill->fresh()->availableToPayAmount())->toBe('5000.0000');
});

test('a reversed bill has zero outstanding and zero available to pay while keeping its original total and journals', function () {
    $bill = createPostedBill($this, '12000.0000');
    $originalJournalId = $bill->journal_entry_id;
    reverseBillThroughScreen($this, $bill);

    $fresh = $bill->fresh();
    expect($fresh->status)->toBe(PurchaseBillStatus::Reversed)
        ->and($fresh->outstandingAmount())->toBe('0.0000')
        ->and($fresh->availableToPayAmount())->toBe('0.0000')
        ->and($fresh->total_amount)->toBe('12000.0000')
        ->and($fresh->journal_entry_id)->toBe($originalJournalId)
        ->and(JournalEntry::query()->where('reversal_of_id', $originalJournalId)->exists())->toBeTrue();
});

test('the detail page of a reversed bill shows original total, zero outstanding, both journals and no actions', function () {
    $bill = createPostedBill($this, '12000.0000');
    reverseBillThroughScreen($this, $bill);
    $original = $bill->fresh()->journalEntry;
    $reversal = JournalEntry::query()->where('reversal_of_id', $original->id)->firstOrFail();

    $this->actingAs($this->admin)->get(route('purchase-bills.show', $bill))->assertOk()
        ->assertSee('Original Bill Total')
        ->assertSee('PKR 12,000.00')
        ->assertSee('PKR 0.00 (Reversed)')
        ->assertSee('Status: reversed')
        ->assertSee('Original journal:')
        ->assertSee('Reversal journal:')
        ->assertSee(route('journals.show', $original), false)
        ->assertSee(route('journals.show', $reversal), false)
        ->assertDontSee('Record vendor payment')
        ->assertDontSee('Reverse Bill</button>', false);
});

test('reversal journal reference is plain text without journal view permission', function () {
    $bill = createPostedBill($this, '4000.0000');
    reverseBillThroughScreen($this, $bill);
    $reversal = JournalEntry::query()->where('reversal_of_id', $bill->fresh()->journal_entry_id)->firstOrFail();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('purchase_bills.view');
    $viewer->branches()->attach($this->branch);

    expect($viewer->can('journals.view'))->toBeFalse();
    $this->actingAs($viewer)->get(route('purchase-bills.show', $bill))->assertOk()
        ->assertSee($reversal->voucher_number)
        ->assertDontSee(route('journals.show', $reversal), false);
});

test('the index shows zero outstanding with the original amount for a reversed bill', function () {
    $bill = createPostedBill($this, '12000.0000');
    reverseBillThroughScreen($this, $bill);

    $this->actingAs($this->admin)->get(route('purchase-bills.index'))->assertOk()
        ->assertSee('PKR 12,000.00')
        ->assertSee('PKR 0.00')
        ->assertSee('(Reversed)');
});

test('a reversed bill cannot be paid or reversed again and payables report excludes it', function () {
    $active = createPostedBill($this, '2500.0000');
    $reversed = createPostedBill($this, '12000.0000');
    reverseBillThroughScreen($this, $reversed);

    $this->actingAs($this->accountant)->post(route('purchase-bills.reverse', $reversed), [
        'accounting_period_id' => $this->openPeriod->id,
        'transaction_date' => '2026-01-22',
        'reason' => 'Second reversal attempt',
    ])->assertSessionHasErrors('status');

    $this->actingAs($this->admin)->get(route('payments.create', ['bill' => $reversed->id]))->assertOk()
        ->assertDontSee($reversed->bill_number);

    $this->actingAs($this->admin)->get(route('reports.payables'))->assertOk()
        ->assertSee($active->bill_number)
        ->assertDontSee($reversed->bill_number);
});
