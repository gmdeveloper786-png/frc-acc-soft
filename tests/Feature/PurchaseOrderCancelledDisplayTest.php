<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Shahrah-e-Faisal']);

    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Accountant->value);
    $this->maker->branches()->attach($this->branch);

    $this->director = User::factory()->create();
    $this->director->assignRole(SystemRole::Director->value);
    $this->director->branches()->attach($this->branch);

    $this->vendor = Vendor::factory()->create(['name' => 'DEMO ABC Stationery Supplier']);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true]);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);

    $this->actingAs($this->maker);
});

function createApprovedPo(object $test, string $unitPrice = '200.0000', string $quantity = '2.0000'): PurchaseOrder
{
    $service = app(PurchaseOrderService::class);
    $order = $service->create([
        'vendor_id' => $test->vendor->id,
        'branch_id' => $test->branch->id,
        'po_date' => '2026-10-15',
        'expected_delivery_date' => '2026-10-31',
        'reference' => 'UAT-PO-CANCEL-REF',
        'description' => 'A4 Paper Ream - Cancellation UAT',
        'lines' => [
            [
                'description' => 'A4 Paper Ream - Cancellation UAT',
                'quantity' => $quantity,
                'unit' => 'pcs',
                'unit_price' => $unitPrice,
            ],
        ],
    ], $test->maker);

    $service->submit($order, $test->maker);

    return $service->approve($order->fresh(), $test->director);
}

test('cancelled purchase order shows available for billing 0.00 and unbilled at cancellation without mutating stored totals', function () {
    $order = createApprovedPo($this, '200.0000', '2.0000');
    expect($order->total_amount)->toBe('400.0000')
        ->and($order->remainingAmount())->toBe('400.0000');

    // Cancel the PO
    $service = app(PurchaseOrderService::class);
    $cancelled = $service->cancel($order, 'Vendor discontinued product', $this->director);
    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled);

    // Verify stored calculations remain unchanged (not zeroed in DB/model)
    expect($cancelled->total_amount)->toBe('400.0000')
        ->and($cancelled->billedAmount())->toBe('0.0000')
        ->and($cancelled->remainingAmount())->toBe('400.0000')
        ->and($cancelled->availableForBillingAmount())->toBe('0.0000')
        ->and($cancelled->lines->first()->quantity)->toBe('2.0000')
        ->and($cancelled->lines->first()->billedQuantity())->toBe('0.0000')
        ->and($cancelled->lines->first()->remainingQuantity())->toBe('2.0000');

    // Visit detail page in browser
    $response = $this->actingAs($this->maker)->get(route('purchase-orders.show', $cancelled));
    $response->assertOk();

    // Verify Available for Billing is 0.00 and Unbilled at Cancellation is 400.00
    $response->assertSee('Available for Billing', false);
    $response->assertSee('PKR 0.00', false);
    $response->assertSee('Unbilled at Cancellation', false);
    $response->assertSee('PKR 400.00', false);

    // Verify table shows Unbilled Qty at Cancellation and 2 pcs with not billable subtext
    $response->assertSee('Unbilled Qty at Cancellation', false);
    $response->assertSee('2 pcs', false);
    $response->assertSee('Not currently billable', false);

    // Verify empty linked bills message for cancelled order
    $response->assertSee('No purchase bills were created before cancellation. Billing is unavailable for cancelled purchase orders.', false);
    $response->assertDontSee('Bills can reference this order once it is approved.', false);

    // Verify normal "Create bill from PO" button is NOT rendered
    $response->assertDontSee(route('purchase-bills.create', ['purchase_order' => $cancelled->id]), false);
});

test('server-side billing is strictly rejected for cancelled purchase orders', function () {
    $order = createApprovedPo($this, '200.0000', '2.0000');
    $service = app(PurchaseOrderService::class);
    $cancelled = $service->cancel($order, 'Order cancelled by agreement', $this->director);

    // Direct create URL returns 403 Forbidden
    $this->actingAs($this->maker)
        ->get(route('purchase-bills.create', ['purchase_order' => $cancelled->id]))
        ->assertForbidden();

    // Server-side attempt to create a bill linked to cancelled PO throws ValidationException
    $billService = app(PurchaseBillService::class);
    expect(fn () => $billService->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'BILL-FORGED-CANCELLED-PO',
        'invoice_date' => '2026-10-31',
        'purchase_order_id' => $cancelled->id,
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Illegal bill against cancelled PO',
                'quantity' => '1.0000',
                'unit_amount' => '200.0000',
                'purchase_order_line_id' => $cancelled->lines->first()->id,
            ],
        ],
    ], $this->maker))->toThrow(ValidationException::class, 'Only an approved or issued purchase order can be billed.');
});

test('active approved purchase order retains standard remaining and billable presentation', function () {
    $order = createApprovedPo($this, '200.0000', '2.0000');

    $response = $this->actingAs($this->maker)->get(route('purchase-orders.show', $order));
    $response->assertOk();

    // Shows standard Remaining card
    $response->assertSee('Remaining', false);
    $response->assertSee('PKR 400.00', false);
    $response->assertDontSee('Available for Billing', false);
    $response->assertDontSee('Unbilled at Cancellation', false);

    // Table shows standard Remaining qty header
    $response->assertSee('Remaining qty', false);
    $response->assertDontSee('Unbilled Qty at Cancellation', false);

    // Empty bills shows standard approval message
    $response->assertSee('Bills can reference this order once it is approved. Receiving goods and billing are separate events from approval.', false);
    $response->assertDontSee('No purchase bills were created before cancellation.', false);

    // Normal Create bill from PO button is present
    $response->assertSee(route('purchase-bills.create', ['purchase_order' => $order->id]), false);
});
