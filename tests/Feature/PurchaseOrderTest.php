<?php

use App\Enums\PurchaseBillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = poUser(SystemRole::Accountant, $this->branch);
    $this->approver = poUser(SystemRole::Approver, $this->branch);
    $this->director = poUser(SystemRole::Director, $this->branch);
    $this->vendor = Vendor::factory()->create();
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true]);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->actingAs($this->maker);
});

function poUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function poPayload(object $test, array $lines = [], array $overrides = []): array
{
    return [
        'vendor_id' => $test->vendor->id, 'branch_id' => $test->branch->id, 'po_date' => '2026-01-10',
        'expected_delivery_date' => '2026-01-20', 'reference' => 'QUO-1', 'description' => 'Isolated PO fixture',
        'lines' => $lines ?: [['description' => 'Therapy mats', 'quantity' => '10.0000', 'unit' => 'pcs', 'unit_price' => '100.0000']],
        ...$overrides,
    ];
}

/** Create a PO of the given total and take it to the approved state. */
function approvedPo(object $test, string $unitPrice = '100.0000', string $quantity = '10.0000'): PurchaseOrder
{
    $service = app(PurchaseOrderService::class);
    $order = $service->create(poPayload($test, [['description' => 'Therapy mats', 'quantity' => $quantity, 'unit_price' => $unitPrice]]), $test->maker);
    $service->submit($order, $test->maker);

    return $service->approve($order->fresh(), $test->director);
}

function poBill(object $test, PurchaseOrder $order, string $number, string $quantity, array $overrides = []): array
{
    return [
        'vendor_id' => $order->vendor_id, 'branch_id' => $order->branch_id, 'payable_account_id' => $test->payable->id,
        'bill_number' => $number, 'invoice_date' => '2026-01-15', 'purchase_order_id' => $order->id,
        'lines' => [['account_id' => $test->expense->id, 'description' => 'Therapy mats', 'quantity' => $quantity, 'unit_amount' => '100.0000', 'purchase_order_line_id' => $order->lines()->first()->id]],
        ...$overrides,
    ];
}

test('valid purchase order is created as a numbered draft with exact decimal totals', function () {
    $order = app(PurchaseOrderService::class)->create(poPayload($this, [
        ['description' => 'Line A', 'quantity' => '3.3333', 'unit_price' => '33.3333', 'discount_amount' => '1.1111', 'tax_amount' => '2.2222'],
        ['description' => 'Line B', 'quantity' => '0.1000', 'unit_price' => '0.2000'],
    ]), $this->maker);

    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->po_number)->toBe('PO-2026-00001')
        ->and($order->currency)->toBe('PKR')
        ->and($order->lines[0]->line_total)->toBe('112.2210')
        ->and($order->lines[1]->line_total)->toBe('0.0200')
        ->and($order->subtotal)->toBe('111.1299')
        ->and($order->discount_total)->toBe('1.1111')
        ->and($order->tax_total)->toBe('2.2222')
        ->and($order->total_amount)->toBe('112.2410')
        ->and($order->approvals()->where('action', 'created')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'purchase_orders.created')->exists())->toBeTrue();
});

test('invalid vendor and invalid lines are rejected', function () {
    $inactive = Vendor::factory()->create(['is_active' => false]);
    $service = app(PurchaseOrderService::class);

    expect(fn () => $service->create(poPayload($this, [], ['vendor_id' => $inactive->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create(poPayload($this, [['description' => 'Over-discounted', 'quantity' => '1.0000', 'unit_price' => '10.0000', 'discount_amount' => '10.0001']]), $this->maker))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::query()->count())->toBe(0);

    $this->post(route('purchase-orders.store'), poPayload($this, [], ['vendor_id' => 999999]))->assertSessionHasErrors('vendor_id');
    $this->post(route('purchase-orders.store'), poPayload($this, [['description' => 'Bad', 'quantity' => '1.00001', 'unit_price' => '-5']]))->assertSessionHasErrors(['lines.0.quantity', 'lines.0.unit_price']);
});

test('unauthorized branch and cross branch access are rejected', function () {
    $otherBranch = Branch::factory()->create(['status' => 'publish']);
    expect(fn () => app(PurchaseOrderService::class)->create(poPayload($this, [], ['branch_id' => $otherBranch->id]), $this->maker))->toThrow(HttpException::class);

    $order = app(PurchaseOrderService::class)->create(poPayload($this), $this->maker);
    app(PurchaseOrderService::class)->submit($order, $this->maker);
    $outsider = poUser(SystemRole::Director, $otherBranch);

    $this->actingAs($outsider)->get(route('purchase-orders.show', $order))->assertForbidden();
    $this->actingAs($outsider)->get(route('purchase-orders.index'))->assertOk()->assertDontSee($order->po_number);
    $this->actingAs($outsider)->post(route('purchase-orders.approve', $order))->assertForbidden();
    expect(fn () => app(PurchaseOrderService::class)->approve($order->fresh(), $outsider))->toThrow(HttpException::class)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});

test('purchase order numbers are sequential, unique and driven by settings', function () {
    $service = app(PurchaseOrderService::class);
    $first = $service->create(poPayload($this), $this->maker);
    $second = $service->create(poPayload($this), $this->maker);
    $nextYear = $service->create(poPayload($this, [], ['po_date' => '2027-02-01', 'expected_delivery_date' => null]), $this->maker);
    expect([$first->po_number, $second->po_number, $nextYear->po_number])->toBe(['PO-2026-00001', 'PO-2026-00002', 'PO-2027-00001']);

    AppSetting::query()->where('key', 'numbering.purchase_order_prefix')->first()->update(['value' => 'FRCPO']);
    AppSetting::query()->where('key', 'numbering.purchase_order_padding')->first()->update(['value' => '3']);
    expect($service->create(poPayload($this), $this->maker)->po_number)->toBe('FRCPO-2026-003');

    expect(fn () => PurchaseOrder::factory()->create(['po_number' => $first->po_number]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $first->update(['po_number' => 'PO-CHANGED']))->toThrow(LogicException::class);
});

test('approval threshold boundary comes from settings', function () {
    $service = app(PurchaseOrderService::class);
    expect($service->approvalTier('150000.0000'))->toBe('standard')
        ->and($service->approvalTier('150000.0001'))->toBe('director')
        ->and($service->approvalPermission('150000.0000'))->toBe('purchase_orders.approve')
        ->and($service->approvalPermission('150000.0001'))->toBe('purchase_orders.approve_high_value');

    AppSetting::query()->where('key', 'approval.purchase_order_threshold')->first()->update(['value' => '500.00']);
    expect($service->approvalTier('500.0001'))->toBe('director')->and($service->approvalTier('500.0000'))->toBe('standard');

    AppSetting::query()->where('key', 'approval.purchase_order_standard_policy')->first()->update(['value' => 'director_only']);
    expect($service->approvalPermission('100.0000'))->toBe('purchase_orders.approve_high_value');
});

test('an order at the threshold is approved by an authorized approver who is not the maker', function () {
    $service = app(PurchaseOrderService::class);
    $order = $service->create(poPayload($this, [['description' => 'Equipment', 'quantity' => '1.0000', 'unit_price' => '150000.0000']]), $this->maker);
    $submitted = $service->submit($order, $this->maker);
    expect($submitted->status)->toBe(PurchaseOrderStatus::Submitted)->and($submitted->approval_tier)->toBe('standard');

    $approved = $service->approve($order->fresh(), $this->approver, 'Within budget');
    expect($approved->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($approved->approved_by)->toBe($this->approver->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->approvals()->where('action', 'approved')->first()->comments)->toBe('Within budget');
});

test('an order above the threshold requires director approval', function () {
    $service = app(PurchaseOrderService::class);
    $order = $service->create(poPayload($this, [['description' => 'Equipment', 'quantity' => '1.0000', 'unit_price' => '150000.0001']]), $this->maker);
    $service->submit($order, $this->maker);
    expect($order->fresh()->approval_tier)->toBe('director');

    expect(fn () => $service->approve($order->fresh(), $this->approver))->toThrow(HttpException::class);
    $this->actingAs($this->approver)->post(route('purchase-orders.approve', $order))->assertForbidden();
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);

    expect($service->approve($order->fresh(), $this->director)->status)->toBe(PurchaseOrderStatus::Approved);
});

test('maker cannot approve or reject and nothing is auto approved', function () {
    $service = app(PurchaseOrderService::class);
    $admin = poUser(SystemRole::Administrator, $this->branch);
    $order = $service->create(poPayload($this), $admin);
    expect($order->status)->toBe(PurchaseOrderStatus::Draft);
    $service->submit($order, $admin);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted)->and($order->fresh()->approved_by)->toBeNull();

    expect(fn () => $service->approve($order->fresh(), $admin))->toThrow(ValidationException::class)
        ->and(fn () => $service->reject($order->fresh(), 'Not allowed', $admin))->toThrow(ValidationException::class)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});

test('duplicate approval and invalid status transitions are rejected', function () {
    $service = app(PurchaseOrderService::class);
    $order = $service->create(poPayload($this), $this->maker);

    expect(fn () => $service->approve($order->fresh(), $this->approver))->toThrow(ValidationException::class)
        ->and(fn () => $service->issue($order->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->close($order->fresh(), null, $this->maker))->toThrow(ValidationException::class);

    $service->submit($order, $this->maker);
    expect(fn () => $service->submit($order->fresh(), $this->maker))->toThrow(ValidationException::class);
    $service->approve($order->fresh(), $this->approver);
    expect(fn () => $service->approve($order->fresh(), $this->director))->toThrow(ValidationException::class)
        ->and(fn () => $service->reject($order->fresh(), 'Too late', $this->director))->toThrow(ValidationException::class)
        ->and(PurchaseOrderApproval::query()->where('purchase_order_id', $order->id)->where('action', 'approved')->count())->toBe(1);

    $issued = $service->issue($order->fresh(), $this->maker);
    expect($issued->status)->toBe(PurchaseOrderStatus::Issued);
    $closed = $service->close($order->fresh(), 'Delivered in full', $this->maker);
    expect($closed->status)->toBe(PurchaseOrderStatus::Closed)
        ->and(fn () => $service->cancel($order->fresh(), 'Cannot cancel closed', $this->director))->toThrow(ValidationException::class);
});

test('rejected order returns to draft on edit and keeps its history', function () {
    $service = app(PurchaseOrderService::class);
    $order = $service->create(poPayload($this), $this->maker);
    $service->submit($order, $this->maker);
    $rejected = $service->reject($order->fresh(), 'Quantity is too high', $this->approver);
    expect($rejected->status)->toBe(PurchaseOrderStatus::Rejected)->and($rejected->rejection_reason)->toBe('Quantity is too high');

    $edited = $service->update($order->fresh(), poPayload($this, [['description' => 'Therapy mats', 'quantity' => '5.0000', 'unit_price' => '100.0000']]), $this->maker);
    expect($edited->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($edited->total_amount)->toBe('500.0000')
        ->and($edited->po_number)->toBe($order->po_number)
        ->and($edited->approvals()->pluck('action')->all())->toBe(['created', 'submitted', 'rejected', 'updated']);
    expect(fn () => $edited->approvals()->first()->update(['comments' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $edited->approvals()->first()->delete())->toThrow(LogicException::class);
});

test('approved purchase order is immutable', function () {
    $order = approvedPo($this);

    expect(fn () => app(PurchaseOrderService::class)->update($order->fresh(), poPayload($this), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $order->fresh()->update(['total_amount' => '1.0000']))->toThrow(LogicException::class)
        ->and(fn () => $order->lines()->first()->update(['quantity' => '99.0000']))->toThrow(LogicException::class)
        ->and(fn () => $order->lines()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => PurchaseOrderLine::query()->create(['purchase_order_id' => $order->id, 'line_number' => 9, 'description' => 'Injected', 'quantity' => '1.0000', 'unit_price' => '1.0000', 'line_total' => '1.0000']))->toThrow(LogicException::class)
        ->and($order->fresh()->total_amount)->toBe('1000.0000');

    $this->get(route('purchase-orders.edit', $order))->assertForbidden();
    $this->put(route('purchase-orders.update', $order), poPayload($this))->assertSessionHasErrors('status');
});

test('cancellation needs permission and a reason and is blocked by linked bills', function () {
    $service = app(PurchaseOrderService::class);
    $order = approvedPo($this);

    expect(fn () => $service->cancel($order->fresh(), 'Maker has no cancel permission', $this->maker))->toThrow(HttpException::class);
    $this->actingAs($this->director)->post(route('purchase-orders.cancel', $order), ['reason' => ''])->assertSessionHasErrors('reason');

    $this->actingAs($this->maker);
    $bill = app(PurchaseBillService::class)->create(poBill($this, $order, 'INV-C-1', '2.0000'), $this->maker);
    expect(fn () => $service->cancel($order->fresh(), 'Vendor withdrew', $this->director))->toThrow(ValidationException::class);

    $bill->update(['status' => PurchaseBillStatus::Rejected]);
    $cancelled = $service->cancel($order->fresh(), 'Vendor withdrew', $this->director);
    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($cancelled->closure_reason)->toBe('Vendor withdrew')
        ->and(fn () => app(PurchaseBillService::class)->create(poBill($this, $order, 'INV-C-2', '1.0000'), $this->maker))->toThrow(ValidationException::class);
});

test('one purchase order supports multiple bills with partial billing and remaining tracking', function () {
    $order = approvedPo($this);
    $bills = app(PurchaseBillService::class);

    $first = $bills->create(poBill($this, $order, 'INV-P-1', '4.0000'), $this->maker);
    expect($first->purchase_order_id)->toBe($order->id)
        ->and($first->purchase_order_reference)->toBe($order->po_number)
        ->and($first->po_exception_status)->toBeNull()
        ->and($order->fresh()->billedAmount())->toBe('400.0000')
        ->and($order->fresh()->remainingAmount())->toBe('600.0000');

    $bills->create(poBill($this, $order, 'INV-P-2', '6.0000'), $this->maker);
    $line = $order->lines()->first();
    expect($order->fresh()->bills()->count())->toBe(2)
        ->and($order->fresh()->remainingAmount())->toBe('0.0000')
        ->and($line->billedQuantity())->toBe('10.0000')
        ->and($line->remainingQuantity())->toBe('0.0000');

    $this->get(route('purchase-orders.show', $order))->assertOk()->assertSee('INV-P-1')->assertSee('INV-P-2')->assertSee('Approval history');
});

test('overbilling is prevented unless a documented exception is separately approved', function () {
    $order = approvedPo($this);
    $bills = app(PurchaseBillService::class);
    $bills->create(poBill($this, $order, 'INV-O-1', '8.0000'), $this->maker);

    expect(fn () => $bills->create(poBill($this, $order, 'INV-O-2', '2.0001'), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $bills->create(poBill($this, $order, 'INV-O-2', '1.0000', ['lines' => [['account_id' => $this->expense->id, 'description' => 'Price increase', 'quantity' => '1.0000', 'unit_amount' => '200.0001']]]), $this->maker))->toThrow(ValidationException::class)
        ->and($order->fresh()->bills()->count())->toBe(1);

    $exception = $bills->create(poBill($this, $order, 'INV-O-3', '3.0000', ['po_exception_reason' => 'Vendor delivered one extra carton accepted by the branch manager']), $this->maker);
    expect($exception->po_exception_status)->toBe('pending');
    $bills->submit($exception, $this->maker);

    $this->actingAs($this->approver);
    expect(fn () => $bills->approve($exception->fresh(), $this->approver))->toThrow(HttpException::class);
    $this->actingAs($this->director);
    $approved = $bills->approve($exception->fresh(), $this->director);
    expect($approved->status)->toBe(PurchaseBillStatus::Approved)
        ->and($approved->po_exception_status)->toBe('approved')
        ->and($approved->po_exception_approved_by)->toBe($this->director->id)
        ->and(AuditLog::query()->where('event', 'purchase_bills.po_exception_approved')->exists())->toBeTrue();
});

test('a bill cannot reference an unapproved order or another vendor branch or order line', function () {
    $bills = app(PurchaseBillService::class);
    $draft = app(PurchaseOrderService::class)->create(poPayload($this), $this->maker);
    expect(fn () => $bills->create(poBill($this, $draft, 'INV-X-1', '1.0000'), $this->maker))->toThrow(ValidationException::class);

    $order = approvedPo($this);
    $other = approvedPo($this);
    $otherVendor = Vendor::factory()->create();
    expect(fn () => $bills->create(poBill($this, $order, 'INV-X-2', '1.0000', ['vendor_id' => $otherVendor->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $bills->create(poBill($this, $order, 'INV-X-3', '1.0000', ['lines' => [['account_id' => $this->expense->id, 'description' => 'Wrong line', 'quantity' => '1.0000', 'unit_amount' => '100.0000', 'purchase_order_line_id' => $other->lines()->first()->id]]]), $this->maker))->toThrow(ValidationException::class);
});

test('purchase order workflow never posts to the general ledger', function () {
    $service = app(PurchaseOrderService::class);
    $order = approvedPo($this);
    $service->issue($order->fresh(), $this->maker);
    app(PurchaseBillService::class)->create(poBill($this, $order, 'INV-GL-1', '5.0000'), $this->maker);
    $service->close($order->fresh(), null, $this->maker);

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(DB::table('journal_lines')->count())->toBe(0);
});

test('each workflow step is audited without sensitive data', function () {
    $service = app(PurchaseOrderService::class);
    $order = approvedPo($this);
    $service->issue($order->fresh(), $this->maker);
    $service->close($order->fresh(), 'Completed', $this->maker);

    $events = AuditLog::query()->where('auditable_type', $order->getMorphClass())->where('auditable_id', $order->id)->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['purchase_orders.created', 'purchase_orders.submitted', 'purchase_orders.approved', 'purchase_orders.issued', 'purchase_orders.closed'])
        ->and(json_encode(AuditLog::query()->pluck('properties')))->not->toContain('password')->not->toContain('token');
});

test('screens enforce permissions and mass assignment cannot set workflow fields', function () {
    $cashier = poUser(SystemRole::Cashier, $this->branch);
    $this->actingAs($cashier)->get(route('purchase-orders.index'))->assertForbidden();
    $this->actingAs($cashier)->post(route('purchase-orders.store'), poPayload($this))->assertForbidden();

    $this->actingAs($this->maker)->get(route('purchase-orders.create'))->assertOk()->assertSee('Order lines');
    $this->post(route('purchase-orders.store'), poPayload($this, [], ['status' => 'approved', 'approved_by' => $this->maker->id, 'po_number' => 'PO-HACK', 'total_amount' => '1.0000', 'currency' => 'USD']))->assertRedirect();
    $order = PurchaseOrder::query()->firstOrFail();
    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->approved_by)->toBeNull()
        ->and($order->po_number)->toBe('PO-2026-00001')
        ->and($order->total_amount)->toBe('1000.0000')
        ->and($order->currency)->toBe('PKR');

    $this->get(route('purchase-orders.index', ['search' => 'PO-2026', 'status' => 'draft']))->assertOk()->assertSee($order->po_number);
    $this->get(route('purchase-orders.index', ['status' => 'approved']))->assertOk()->assertSee('No purchase orders found');
    $this->get(route('purchase-orders.edit', $order))->assertOk()->assertSee('Therapy mats');
    $this->actingAs(poUser(SystemRole::Cashier, $this->branch))->get(route('purchase-orders.show', $order))->assertForbidden();
});

test('an authorized user can edit a draft purchase bill without creating a journal', function () {
    $service = app(PurchaseBillService::class);
    $bill = $service->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-EDIT-001',
        'invoice_date' => '2026-01-15',
        'description' => 'Original draft',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Original line', 'quantity' => '1.0000', 'unit_amount' => '100.0000']],
    ], $this->maker);

    $this->get(route('purchase-bills.edit', $bill))->assertOk()->assertSee('Save draft changes');
    $this->put(route('purchase-bills.update', $bill), [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-EDIT-001',
        'invoice_date' => '2026-01-16',
        'description' => 'Corrected draft',
        'expected_updated_at' => $bill->updated_at->format('Y-m-d H:i:s.u'),
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Corrected line', 'quantity' => '2.0000', 'unit_amount' => '100.0000']],
    ])->assertRedirect(route('purchase-bills.show', $bill));

    expect($bill->fresh()->status)->toBe(PurchaseBillStatus::Draft)
        ->and($bill->fresh()->total_amount)->toBe('200.0000')
        ->and($bill->fresh()->description)->toBe('Corrected draft')
        ->and($bill->fresh()->lines)->toHaveCount(1)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'purchase_bills.updated')->exists())->toBeTrue();
});

test('the purchase bill listing renders draft and non-draft bills with edit offered only on drafts', function () {
    $service = app(PurchaseBillService::class);
    $bill = fn (string $number) => $service->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => $number,
        'invoice_date' => '2026-01-15',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Listing line', 'quantity' => '1.0000', 'unit_amount' => '100.0000']],
    ], $this->maker);
    $draft = $bill('PB-LIST-DRAFT');
    $submitted = $bill('PB-LIST-SUBMITTED');
    $submitted->update(['status' => PurchaseBillStatus::Submitted]);

    $this->get(route('purchase-bills.index'))->assertOk()
        ->assertSee(['PB-LIST-DRAFT', 'PB-LIST-SUBMITTED', 'draft', 'submitted'])
        ->assertSee(route('purchase-bills.show', $draft), false)
        ->assertSee(route('purchase-bills.show', $submitted), false)
        ->assertSee(route('purchase-bills.edit', $draft), false)
        ->assertDontSee(route('purchase-bills.edit', $submitted), false);

    // A viewer without purchase_bills.update sees the bills but no Edit action.
    $this->actingAs($this->approver)->get(route('purchase-bills.index'))->assertOk()
        ->assertSee(['PB-LIST-DRAFT', 'PB-LIST-SUBMITTED'])
        ->assertDontSee(route('purchase-bills.edit', $draft), false);
});

test('purchase bill edit rejects stale, unauthorized, non-draft, and ineligible changes', function () {
    $service = app(PurchaseBillService::class);
    $bill = $service->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-EDIT-002',
        'invoice_date' => '2026-01-15',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Draft line', 'quantity' => '1.0000', 'unit_amount' => '100.0000']],
    ], $this->maker);
    $data = [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => $bill->bill_number,
        'invoice_date' => '2026-01-15',
        'expected_updated_at' => '2000-01-01 00:00:00.000000',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Draft line', 'quantity' => '1.0000', 'unit_amount' => '100.0000']],
    ];

    $this->put(route('purchase-bills.update', $bill), $data)->assertSessionHasErrors('status');
    $bill->update(['status' => PurchaseBillStatus::Submitted]);
    $data['expected_updated_at'] = $bill->fresh()->updated_at->format('Y-m-d H:i:s.u');
    $this->put(route('purchase-bills.update', $bill), $data)->assertSessionHasErrors('status');

    $otherBranch = Branch::factory()->create(['status' => 'publish']);
    $outsider = poUser(SystemRole::Accountant, $otherBranch);
    $this->actingAs($outsider)->get(route('purchase-bills.edit', $bill))->assertForbidden();
});
