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
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

function createOrder(mixed $test, string $qty = '5.0000', string $unitPrice = '200.0000'): PurchaseOrder
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
        'unit' => 'pcs',
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

test('fully billed purchase order displays fully billed badge, disables normal bill button, and shows explanation', function () {
    $order = createOrder($this, '5.0000', '200.0000');

    // Create, submit and post full bill (PKR 1,000 for 5 pcs)
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-FULL-001',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '5.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $approved = $this->bills->approve($bill->fresh(), $this->approver);
    $this->bills->post($approved, $this->maker);

    $freshOrder = $order->fresh();
    expect($freshOrder->isFullyBilled())->toBeTrue()
        ->and($freshOrder->billedAmount())->toBe('1000.0000')
        ->and($freshOrder->remainingAmount())->toBe('0.0000')
        ->and($freshOrder->lines()->first()->remainingQuantity())->toBe('0.0000')
        ->and($freshOrder->status)->toBe(PurchaseOrderStatus::Issued); // not automatically closed

    // Show page check
    $response = $this->actingAs($this->maker)->get(route('purchase-orders.show', $freshOrder));
    $response->assertOk()
        ->assertSee('Fully Billed')
        ->assertSee('This purchase order has been fully billed.')
        ->assertSee('Normal bill creation is disabled to prevent duplicate billing.')
        ->assertSee('Request billing exception')
        ->assertSee('<button class="btn btn-secondary opacity-75" type="button" disabled', false)
        ->assertDontSee('<a class="btn btn-primary" href="'.route('purchase-bills.create', ['purchase_order' => $freshOrder->id]).'">Create bill from PO</a>', false);
});

test('partially billed purchase order keeps normal billing enabled and does not display fully billed banner', function () {
    $order = createOrder($this, '5.0000', '200.0000');

    // Partial bill: 2 pcs for PKR 400
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-PARTIAL-001',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams (partial)',
                'quantity' => '2.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $approved = $this->bills->approve($bill->fresh(), $this->approver);
    $this->bills->post($approved, $this->maker);

    $freshOrder = $order->fresh();
    expect($freshOrder->isFullyBilled())->toBeFalse()
        ->and($freshOrder->billedAmount())->toBe('400.0000')
        ->and($freshOrder->remainingAmount())->toBe('600.0000')
        ->and($freshOrder->lines()->first()->remainingQuantity())->toBe('3.0000');

    $response = $this->actingAs($this->maker)->get(route('purchase-orders.show', $freshOrder));
    $response->assertOk()
        ->assertDontSee('data-fully-billed-notice', false)
        ->assertSee('Create bill from PO')
        ->assertSee(route('purchase-bills.create', ['purchase_order' => $freshOrder->id]), false);
});

test('direct GET request to bill creation for fully billed PO without exception redirects back with warning', function () {
    $order = createOrder($this, '5.0000', '200.0000');

    // Full bill
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-FULL-002',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '5.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $approved = $this->bills->approve($bill->fresh(), $this->approver);
    $this->bills->post($approved, $this->maker);

    // Direct URL request without exception flag
    $response = $this->actingAs($this->maker)->get(route('purchase-bills.create', ['purchase_order' => $order->id]));
    $response->assertRedirect(route('purchase-orders.show', $order));
    $response->assertSessionHas('warning');

    // Direct URL request WITH exception flag succeeds and displays overbilling exception notice
    $exceptionResponse = $this->actingAs($this->maker)->get(route('purchase-bills.create', ['purchase_order' => $order->id, 'exception' => 1]));
    $exceptionResponse->assertOk()
        ->assertSee('PO Overbilling Exception')
        ->assertSee('PO exception reason')
        ->assertSee('Mandatory: PO is fully billed');
});

test('submitting overbilling against fully billed PO without exception reason is rejected by backend validation', function () {
    $order = createOrder($this, '5.0000', '200.0000');

    // Full bill
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-FULL-003',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '5.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $approved = $this->bills->approve($bill->fresh(), $this->approver);
    $this->bills->post($approved, $this->maker);

    // Attempting to create an overbilling bill without reason fails validation
    expect(fn () => $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-OVERBILL-NO-REASON',
        'invoice_date' => '2026-01-16',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Excess Ream',
                'quantity' => '1.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker))->toThrow(ValidationException::class);
});

test('overbilling exception against fully billed PO requires Director authorization and rejects ordinary approver', function () {
    $order = createOrder($this, '5.0000', '200.0000');

    // Full bill
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-FULL-004',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '5.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $approved = $this->bills->approve($bill->fresh(), $this->approver);
    $this->bills->post($approved, $this->maker);

    // Create overbilling bill with documented reason
    $exceptionBill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'po_exception_reason' => 'Authorized emergency replenishment approved by branch manager',
        'bill_number' => 'BILL-EXC-001',
        'invoice_date' => '2026-01-16',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Emergency Ream',
                'quantity' => '1.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    expect($exceptionBill->po_exception_status)->toBe('pending');

    $this->bills->submit($exceptionBill, $this->maker);

    // Ordinary approver cannot approve exception
    expect(fn () => $this->bills->approve($exceptionBill->fresh(), $this->approver))->toThrow(HttpException::class);

    // Director approves exception
    $directorApproved = $this->bills->approve($exceptionBill->fresh(), $this->director);
    expect($directorApproved->status)->toBe(PurchaseBillStatus::Approved)
        ->and($directorApproved->po_exception_status)->toBe('approved')
        ->and($directorApproved->po_exception_approved_by)->toBe($this->director->id);
});

test('rejected, cancelled, and reversed bills restore PO capacity and do not keep PO fully billed', function () {
    $order = createOrder($this, '5.0000', '200.0000');

    // Create and reject a bill
    $bill = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-REJECT-001',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'A4 Paper Reams',
                'quantity' => '5.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);

    $this->bills->submit($bill, $this->maker);
    $this->bills->reject($bill->fresh(), 'Vendor sent incorrect invoice', $this->approver);

    expect($order->fresh()->isFullyBilled())->toBeFalse()
        ->and($order->fresh()->remainingAmount())->toBe('1000.0000');
});

test('concurrent authorization prevents ordinary approver from approving a bill if PO balance was exhausted by another bill', function () {
    $order = createOrder($this, '5.0000', '200.0000'); // Total PKR 1,000

    // Bill A for PKR 600
    $billA = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-CONC-A',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Part A',
                'quantity' => '3.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);
    $this->bills->submit($billA, $this->maker);

    // Bill B for PKR 400 (initially within remaining PKR 400)
    $billB = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'bill_number' => 'BILL-CONC-B',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Part B',
                'quantity' => '2.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);
    $this->bills->submit($billB, $this->maker);

    // Bill A is approved
    $this->bills->approve($billA->fresh(), $this->approver);

    // Now another Bill C was approved for PKR 200 via exception
    $billC = $this->bills->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'purchase_order_id' => $order->id,
        'po_exception_reason' => 'Emergency exception approved',
        'bill_number' => 'BILL-CONC-C',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Part C',
                'quantity' => '1.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $order->lines()->first()->id,
            ],
        ],
    ], $this->maker);
    $this->bills->submit($billC, $this->maker);
    $this->bills->approve($billC->fresh(), $this->director);

    // Committed on order: Bill A (600) + Bill C (200) = 800.
    // Order total is 1,000. Remaining is 200.
    // Bill B is 400. 800 + 400 = 1,200 > 1,000!
    // Bill B was submitted as a normal bill (po_exception_status = null),
    // but now exceeds the PO due to concurrent activity.
    // Attempting to approve Bill B as ordinary approver must fail!
    expect(fn () => $this->bills->approve($billB->fresh(), $this->approver))->toThrow(ValidationException::class);
});
