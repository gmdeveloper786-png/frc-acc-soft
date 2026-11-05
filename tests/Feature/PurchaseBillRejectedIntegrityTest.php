<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PurchaseBillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Shahrah-e-Faisal']);

    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Accountant->value);
    $this->maker->branches()->attach($this->branch);

    $this->approver = User::factory()->create();
    $this->approver->assignRole(SystemRole::Approver->value);
    $this->approver->branches()->attach($this->branch);

    $this->director = User::factory()->create();
    $this->director->assignRole(SystemRole::Director->value);
    $this->director->branches()->attach($this->branch);

    $this->vendor = Vendor::factory()->create(['name' => 'DEMO ABC Stationery Supplier']);
    $this->expense = Account::factory()->create([
        'code' => '53209',
        'name' => 'Office Stationery Expense',
        'account_type' => 'expense',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);
    $this->payable = Account::factory()->create([
        'code' => '21101',
        'name' => 'Trade Creditors / Suppliers',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
    ]);

    $year = FiscalYear::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);
    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $year->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->bills = app(PurchaseBillService::class);
});

function createApprovedOrder(mixed $test, string $qty = '5.0000', string $unitPrice = '200.0000'): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create([
        'branch_id' => $test->branch->id,
        'vendor_id' => $test->vendor->id,
        'po_number' => 'PO-2026-00001',
        'status' => PurchaseOrderStatus::Draft,
        'subtotal' => bcmul($qty, $unitPrice, 4),
        'total_amount' => bcmul($qty, $unitPrice, 4),
        'created_by' => $test->maker->id,
    ]);
    $order->lines()->create([
        'line_number' => 1,
        'description' => 'A4 Paper Reams',
        'quantity' => $qty,
        'unit' => 'ream',
        'unit_price' => $unitPrice,
        'line_total' => bcmul($qty, $unitPrice, 4),
        'account_id' => $test->expense->id,
    ]);
    $order->update([
        'status' => PurchaseOrderStatus::Issued,
        'approved_by' => $test->approver->id,
    ]);

    return $order;
}

test('rejected bill exception badge is not displayed as actively pending on bill and order pages', function () {
    $order = createApprovedOrder($this, '5.0000', '200.0000');
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'po_exception_reason' => 'Vendor delivered 6 units instead of 5 accepted for test',
        'bill_number' => 'UAT-BILL-PO-001',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '6.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);

    // Active submitted state check
    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Submitted)
        ->and($bill->fresh()->poExceptionDisplay()['label'])->toBe('Exception pending')
        ->and($bill->fresh()->poExceptionDisplay()['detail_label'])->toBe('PO exception pending')
        ->and($bill->fresh()->poExceptionDisplay()['is_pending'])->toBeTrue();

    // Approver returns for correction (reject)
    $this->bills->reject($bill->fresh(), 'Excess quantity exceeds approved PO by PKR 200. Returned for correction.', $this->approver);

    $fresh = $bill->fresh();
    expect($fresh->status)->toBe(PurchaseBillStatus::Rejected)
        ->and($fresh->po_exception_status)->toBe('pending') // database value unchanged
        ->and($fresh->poExceptionDisplay()['label'])->toBe('Exception rejected')
        ->and($fresh->poExceptionDisplay()['detail_label'])->toBe('PO exception rejected')
        ->and($fresh->poExceptionDisplay()['tone'])->toBe('danger')
        ->and($fresh->poExceptionDisplay()['is_pending'])->toBeFalse();

    // Bill detail page display check
    $this->actingAs($this->maker)->get(route('purchase-bills.show', $fresh))
        ->assertOk()
        ->assertSee('PO exception rejected')
        ->assertDontSee('PO exception pending')
        ->assertDontSee('Approval requires a user authorized for PO billing exceptions.')
        ->assertSee('Vendor delivered 6 units instead of 5 accepted for test')
        ->assertSee('Excess quantity exceeds approved PO by PKR 200. Returned for correction.');

    // Purchase order linked-bills table display check
    $this->actingAs($this->maker)->get(route('purchase-orders.show', $order))
        ->assertOk()
        ->assertSee('Exception rejected')
        ->assertDontSee('Exception pending')
        ->assertSee('Vendor delivered 6 units instead of 5 accepted for test');
});

test('rejected bill preserves historical total and displays zero active payable without creating general ledger entries', function () {
    $order = createApprovedOrder($this, '5.0000', '200.0000');
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'po_exception_reason' => 'Vendor delivered 6 units instead of 5 accepted for test',
        'bill_number' => 'UAT-BILL-PO-001',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '6.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $this->bills->reject($bill->fresh(), 'Excess quantity. Returned for correction.', $this->approver);

    $fresh = $bill->fresh();

    // 1. Total remains historically accurate
    expect((float) $fresh->total_amount)->toBe(1200.0)
        // 2. Outstanding & available to pay are 0
        ->and($fresh->outstandingAmount())->toBe('0.0000')
        ->and($fresh->availableToPayAmount())->toBe('0.0000')
        // 3. No journal entry or GL posting created
        ->and($fresh->journal_entry_id)->toBeNull()
        ->and(JournalEntry::query()->where('source_id', (string) $fresh->id)->exists())->toBeFalse();

    // 4. Linked PO billed amount & quantities are fully restored
    $poFresh = $order->fresh();
    expect($poFresh->billedAmount())->toBe('0.0000')
        ->and($poFresh->remainingAmount())->toBe('1000.0000')
        ->and($poFresh->lines->first()->billedQuantity())->toBe('0.0000')
        ->and($poFresh->lines->first()->remainingQuantity())->toBe('5.0000');

    // 5. Bill detail view renders Original Bill Total and Active Payable: PKR 0.00 (Rejected — Not Payable)
    $this->actingAs($this->maker)->get(route('purchase-bills.show', $fresh))
        ->assertOk()
        ->assertSee('Original Bill Total')
        ->assertSee('PKR 1,200.00')
        ->assertSee('Active Payable')
        ->assertSee('PKR 0.00')
        ->assertSee('(Rejected — Not Payable)');

    // 6. Bills index renders PKR 0.00 (Rejected)
    $this->actingAs($this->maker)->get(route('purchase-bills.index'))
        ->assertOk()
        ->assertSee('PKR 1,200.00')
        ->assertSee('PKR 0.00')
        ->assertSee('(Rejected)');
});

test('normal billing of 5 remaining units is not blocked by a rejected bill', function () {
    $order = createApprovedOrder($this, '5.0000', '200.0000');

    // Create and reject the excess bill
    $rejectedBill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'po_exception_reason' => 'Vendor delivered 6 units instead of 5 accepted for test',
        'bill_number' => 'UAT-BILL-PO-REJECTED',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '6.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($rejectedBill, $this->maker);
    $this->bills->reject($rejectedBill->fresh(), 'Excess quantity. Returned for correction.', $this->approver);

    // Normal bill for the original approved 5 units (PKR 1,000) succeeds without requiring an exception
    $normalBill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'UAT-BILL-PO-NORMAL',
        'invoice_date' => '2026-01-16',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams (normal)',
                'quantity' => '5.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    expect($normalBill->po_exception_status)->toBeNull()
        ->and($normalBill->po_exception_reason)->toBeNull()
        ->and((float) $normalBill->total_amount)->toBe(1000.0)
        ->and($normalBill->outstandingAmount())->toBe('1000.0000');

    // Submit, approve and post normal bill
    $this->bills->submit($normalBill, $this->maker);
    $approved = $this->bills->approve($normalBill->fresh(), $this->approver);
    $posted = $this->bills->post($approved, $this->maker);

    expect($posted->status)->toBe(PurchaseBillStatus::Posted)
        ->and($posted->journal_entry_id)->not->toBeNull()
        ->and($posted->outstandingAmount())->toBe('1000.0000')
        ->and($order->fresh()->billedAmount())->toBe('1000.0000')
        ->and($order->fresh()->remainingAmount())->toBe('0.0000')
        ->and($order->lines()->first()->billedQuantity())->toBe('5.0000')
        ->and($order->lines()->first()->remainingQuantity())->toBe('0.0000');
});
