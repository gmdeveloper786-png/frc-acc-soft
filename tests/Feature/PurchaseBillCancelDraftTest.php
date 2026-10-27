<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PurchaseBillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);

    $this->admin = cancelDraftUser(SystemRole::Administrator, $this->branch);
    $this->accountant = cancelDraftUser(SystemRole::Accountant, $this->branch);
    $this->otherAccountant = cancelDraftUser(SystemRole::Accountant, $this->otherBranch);
    $this->approver = cancelDraftUser(SystemRole::Approver, $this->branch);
    $this->auditor = cancelDraftUser(SystemRole::Auditor, $this->branch);
    $this->cashier = cancelDraftUser(SystemRole::Cashier, $this->branch);

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

    $this->bills = app(PurchaseBillService::class);
});

function cancelDraftUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function createDraftBill(object $test, string $amount = '5000.0000'): PurchaseBill
{
    return $test->bills->create([
        'vendor_id' => $test->vendor->id,
        'branch_id' => $test->branch->id,
        'payable_account_id' => $test->payable->id,
        'bill_number' => 'PB-DRAFT-'.uniqid(),
        'invoice_date' => '2026-01-15',
        'lines' => [[
            'account_id' => $test->expense->id,
            'description' => 'Draft test item',
            'quantity' => '1.0000',
            'unit_amount' => $amount,
        ]],
    ], $test->accountant);
}

test('authorized accountant sees cancel draft form on draft bill', function () {
    $bill = createDraftBill($this);

    expect($this->bills->canCancelDraft($bill, $this->accountant))->toBeTrue();

    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertSee('Cancel Draft')
        ->assertSee(route('purchase-bills.cancel-draft', $bill), false)
        ->assertSee('Cancellation Reason');
});

test('accountant can successfully cancel a draft purchase bill with audit trail', function () {
    $bill = createDraftBill($this, '12000.0000');
    $reason = 'Vendor issued incorrect pricing; replacement bill required.';

    $response = $this->actingAs($this->accountant)->post(route('purchase-bills.cancel-draft', $bill), [
        'reason' => $reason,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Purchase bill draft cancelled.');

    $bill->refresh();
    expect($bill->status)->toBe(PurchaseBillStatus::Cancelled)
        ->and((float) $bill->total_amount)->toBe(12000.0)
        ->and($bill->outstandingAmount())->toBe('0.0000')
        ->and($bill->availableToPayAmount())->toBe('0.0000')
        ->and($bill->journal_entry_id)->toBeNull();

    // Verify audit log recorded actor, reason, timestamp
    $log = AuditLog::query()
        ->where('auditable_type', $bill->getMorphClass())
        ->where('auditable_id', $bill->id)
        ->where('event', 'purchase_bills.cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($this->accountant->id)
        ->and($log->properties['reason'])->toBe($reason)
        ->and($log->properties['from_status'])->toBe('draft');
});

test('original bill record, lines, vendor, and amounts are preserved upon cancellation', function () {
    $bill = createDraftBill($this, '8500.0000');
    $initialLineCount = $bill->lines()->count();
    $vendorId = $bill->vendor_id;
    $creatorId = $bill->created_by;

    $this->bills->cancelDraft($bill, 'Cancelled due to double entry', $this->accountant);

    $bill->refresh();
    expect($bill->status)->toBe(PurchaseBillStatus::Cancelled)
        ->and($bill->lines()->count())->toBe($initialLineCount)
        ->and($bill->vendor_id)->toBe($vendorId)
        ->and($bill->created_by)->toBe($creatorId)
        ->and((float) $bill->total_amount)->toBe(8500.0);
});

test('cancellation requires a valid mandatory reason between 5 and 2000 characters', function () {
    $bill = createDraftBill($this);

    // Empty reason
    $this->actingAs($this->accountant)->post(route('purchase-bills.cancel-draft', $bill), [
        'reason' => '',
    ])->assertSessionHasErrors('reason');

    // Too short (< 5 chars)
    $this->actingAs($this->accountant)->post(route('purchase-bills.cancel-draft', $bill), [
        'reason' => 'bad',
    ])->assertSessionHasErrors('reason');

    // Service-level direct validation
    expect(fn () => $this->bills->cancelDraft($bill, '1234', $this->accountant))
        ->toThrow(ValidationException::class);

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Draft);
});

test('unauthorized users without purchase_bills.cancel permission cannot cancel draft bill', function () {
    $bill = createDraftBill($this);

    // Cashier
    $this->actingAs($this->cashier)->post(route('purchase-bills.cancel-draft', $bill), [
        'reason' => 'Unauthorized cancellation attempt',
    ])->assertForbidden();

    // Auditor
    $this->actingAs($this->auditor)->post(route('purchase-bills.cancel-draft', $bill), [
        'reason' => 'Unauthorized cancellation attempt',
    ])->assertForbidden();

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Draft);
});

test('cross branch user cannot cancel draft bill of another branch', function () {
    $bill = createDraftBill($this);

    $this->actingAs($this->otherAccountant)->post(route('purchase-bills.cancel-draft', $bill), [
        'reason' => 'Cross-branch cancellation attempt',
    ])->assertForbidden();

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Draft);
});

test('no GL journal entry or liability is created when draft bill is cancelled', function () {
    $journalCountBefore = JournalEntry::count();
    $bill = createDraftBill($this, '15000.0000');

    $this->bills->cancelDraft($bill, 'Cancelled before submission', $this->accountant);

    expect(JournalEntry::count())->toBe($journalCountBefore)
        ->and($bill->fresh()->journalEntry)->toBeNull();
});

test('cancelled bill displays correctly in index and detail views', function () {
    $bill = createDraftBill($this, '9000.0000');
    $this->bills->cancelDraft($bill, 'Detailed reason for testing display', $this->accountant);

    // Index view
    $this->actingAs($this->accountant)->get(route('purchase-bills.index'))
        ->assertOk()
        ->assertSee($bill->bill_number)
        ->assertSee('cancelled')
        ->assertSee('(Cancelled)');

    // Detail view
    $this->actingAs($this->accountant)->get(route('purchase-bills.show', $bill))
        ->assertOk()
        ->assertSee('Status: cancelled')
        ->assertSee('This draft purchase bill has been cancelled.')
        ->assertSee('Detailed reason for testing display')
        ->assertSee($this->accountant->name)
        ->assertSee('Original Bill Total')
        ->assertSee('(Cancelled)')
        ->assertDontSee('Edit draft');
});

test('cancelled bill cannot be edited, submitted, approved, posted, or cancelled again', function () {
    $bill = createDraftBill($this);
    $this->bills->cancelDraft($bill, 'Cancelled draft', $this->accountant);

    // Edit attempt
    $this->actingAs($this->accountant)->get(route('purchase-bills.edit', $bill))
        ->assertForbidden();

    // Submit attempt
    expect(fn () => $this->bills->submit($bill, $this->accountant))
        ->toThrow(ValidationException::class);

    // Approve attempt
    expect(fn () => $this->bills->approve($bill, $this->approver))
        ->toThrow(ValidationException::class);

    // Post attempt
    expect(fn () => $this->bills->post($bill, $this->accountant))
        ->toThrow(ValidationException::class);

    // Cancel again attempt
    expect(fn () => $this->bills->cancelDraft($bill, 'Another cancellation', $this->accountant))
        ->toThrow(ValidationException::class);
});

test('non-draft bills cannot be cancelled via cancelDraft', function () {
    $bill = createDraftBill($this);

    // Submit bill
    $this->bills->submit($bill, $this->accountant);
    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Submitted);

    expect(fn () => $this->bills->cancelDraft($bill->fresh(), 'Cancel submitted bill', $this->accountant))
        ->toThrow(ValidationException::class);

    // Approve bill
    $this->bills->approve($bill->fresh(), $this->approver);
    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Approved);

    expect(fn () => $this->bills->cancelDraft($bill->fresh(), 'Cancel approved bill', $this->accountant))
        ->toThrow(ValidationException::class);

    // Post bill
    $this->bills->post($bill->fresh(), $this->accountant);
    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted);

    expect(fn () => $this->bills->cancelDraft($bill->fresh(), 'Cancel posted bill', $this->accountant))
        ->toThrow(ValidationException::class);

    // Reverse bill
    $this->bills->reverse($bill->fresh(), $this->openPeriod, '2026-01-20', 'Reversing for test', $this->accountant);
    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Reversed);

    expect(fn () => $this->bills->cancelDraft($bill->fresh(), 'Cancel reversed bill', $this->accountant))
        ->toThrow(ValidationException::class);
});

test('cancelling a draft bill linked to a purchase order releases the reservation on the PO', function () {
    $order = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'vendor_id' => $this->vendor->id,
        'status' => PurchaseOrderStatus::Draft,
        'total_amount' => '10000.0000',
    ]);
    $line = $order->lines()->create([
        'line_number' => 1,
        'description' => 'PO item',
        'account_id' => $this->expense->id,
        'quantity' => '2.0000',
        'unit_price' => '5000.0000',
        'line_total' => '10000.0000',
    ]);
    $order->update(['status' => PurchaseOrderStatus::Approved]);

    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-PO-'.uniqid(),
        'invoice_date' => '2026-01-15',
        'purchase_order_id' => $order->id,
        'lines' => [[
            'account_id' => $this->expense->id,
            'description' => 'PO item',
            'quantity' => '1.0000',
            'unit_amount' => '5000.0000',
            'purchase_order_line_id' => $line->id,
        ]],
    ], $this->accountant);

    expect($order->activeBills()->count())->toBe(1)
        ->and((float) $line->billedQuantity())->toBe(1.0);

    // Cancel draft
    $this->bills->cancelDraft($bill, 'Cancelled PO draft bill', $this->accountant);

    expect($order->activeBills()->count())->toBe(0)
        ->and((float) $line->billedQuantity())->toBe(0.0);
});
