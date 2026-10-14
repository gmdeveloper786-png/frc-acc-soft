<?php

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\InventoryCatalogService;
use App\Services\Inventory\InventoryService;
use App\Services\PurchaseOrderService;
use Brick\Math\BigDecimal;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = inventoryUser(SystemRole::Accountant, $this->branch);
    $this->approver = inventoryUser(SystemRole::Approver, $this->branch);
    $this->location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $this->otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $this->item = InventoryItem::factory()->create(['created_by' => $this->maker->id]);
    $this->actingAs($this->maker);
});

function inventoryUser(SystemRole $role, Branch ...$branches): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    foreach ($branches as $branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

function inventoryPayload(object $test, string $quantity, array $overrides = []): array
{
    return [
        'item_id' => $test->item->id, 'branch_id' => $test->branch->id, 'location_id' => $test->location->id,
        'quantity' => $quantity, 'transaction_date' => '2026-01-15', 'reference' => 'REF-1',
        ...$overrides,
    ];
}

function inventoryService(): InventoryService
{
    return app(InventoryService::class);
}

function inventoryBalance(object $test, ?InventoryLocation $location = null): string
{
    $location ??= $test->location;

    return inventoryService()->quantityOnHand($test->item, $location->branch, $location);
}

function inventorySetting(string $value): void
{
    AppSetting::query()->where('key', 'inventory.allow_negative_stock')->first()->update(['value' => $value]);
}

/** Take a purchase order for the given branch through the real approval workflow. */
function inventoryApprovedPurchaseOrder(object $test, Branch $branch): PurchaseOrder
{
    $buyer = inventoryUser(SystemRole::Accountant, $branch);
    $director = inventoryUser(SystemRole::Director, $branch);
    $service = app(PurchaseOrderService::class);
    $order = $service->create([
        'vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $branch->id, 'po_date' => '2026-01-10',
        'lines' => [['description' => 'Therapy mats', 'quantity' => '10.0000', 'unit_price' => '100.0000']],
    ], $buyer);
    $service->submit($order, $buyer);

    return $service->approve($order->fresh(), $director);
}

test('receiving increases the balance and is audited', function () {
    $movement = inventoryService()->receive(inventoryPayload($this, '12.5000'), $this->maker);

    expect($movement->type)->toBe(InventoryMovementType::Receipt)
        ->and($movement->status)->toBe(InventoryMovementStatus::Posted)
        ->and($movement->quantity)->toBe('12.5000')
        ->and($movement->created_by)->toBe($this->maker->id)
        ->and(inventoryBalance($this))->toBe('12.5000')
        ->and(AuditLog::query()->where('event', 'inventory.received')->where('auditable_id', $movement->id)->exists())->toBeTrue();

    inventoryService()->receive(inventoryPayload($this, '0.0001'), $this->maker);
    expect(inventoryBalance($this))->toBe('12.5001');
});

test('issuing decreases the balance and records the recipient', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    $movement = inventoryService()->issue(inventoryPayload($this, '4.2500', ['issued_to' => 'Occupational Therapy Room']), $this->maker);

    expect($movement->type)->toBe(InventoryMovementType::Issue)
        ->and($movement->quantity)->toBe('-4.2500')
        ->and($movement->issued_to)->toBe('Occupational Therapy Room')
        ->and(inventoryBalance($this))->toBe('5.7500')
        ->and(AuditLog::query()->where('event', 'inventory.issued')->exists())->toBeTrue();
});

test('issue without a recipient is rejected', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);

    expect(fn () => inventoryService()->issue(inventoryPayload($this, '1'), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->issue(inventoryPayload($this, '1', ['issued_to' => '  ']), $this->maker))->toThrow(ValidationException::class);
    $this->post(route('inventory.issue.store'), inventoryPayload($this, '1'))->assertSessionHasErrors('issued_to');

    expect(inventoryBalance($this))->toBe('10.0000')
        ->and(InventoryMovement::query()->where('type', 'issue')->count())->toBe(0);
});

test('insufficient stock is rejected and the balance is unchanged', function () {
    inventoryService()->receive(inventoryPayload($this, '5'), $this->maker);

    expect(fn () => inventoryService()->issue(inventoryPayload($this, '5.0001', ['issued_to' => 'Ward A']), $this->maker))->toThrow(ValidationException::class);
    $this->post(route('inventory.issue.store'), inventoryPayload($this, '6', ['issued_to' => 'Ward A']))->assertSessionHasErrors('quantity');

    expect(inventoryBalance($this))->toBe('5.0000')
        ->and(InventoryMovement::query()->count())->toBe(1);
});

test('invalid quantities are rejected', function () {
    foreach (['0', '-1', '1.00001', 'abc', ''] as $quantity) {
        expect(fn () => inventoryService()->receive(inventoryPayload($this, $quantity), $this->maker))->toThrow(ValidationException::class);
    }
    $this->post(route('inventory.receive.store'), inventoryPayload($this, '1.00001'))->assertSessionHasErrors('quantity');
    $this->post(route('inventory.receive.store'), inventoryPayload($this, '0'))->assertSessionHasErrors('quantity');

    expect(InventoryMovement::query()->count())->toBe(0);
});

test('negative stock is allowed only when the setting is enabled', function () {
    inventoryService()->receive(inventoryPayload($this, '2'), $this->maker);
    expect(fn () => inventoryService()->issue(inventoryPayload($this, '3', ['issued_to' => 'Ward A']), $this->maker))->toThrow(ValidationException::class);

    inventorySetting('1');
    inventoryService()->issue(inventoryPayload($this, '3', ['issued_to' => 'Ward A']), $this->maker);
    expect(inventoryBalance($this))->toBe('-1.0000');

    inventorySetting('0');
    expect(fn () => inventoryService()->issue(inventoryPayload($this, '1', ['issued_to' => 'Ward A']), $this->maker))->toThrow(ValidationException::class);
    inventoryService()->receive(inventoryPayload($this, '0.5'), $this->maker);
    expect(inventoryBalance($this))->toBe('-0.5000');
});

test('sequential over-issue fails and the balance equals the sum of posted movements', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    inventoryService()->issue(inventoryPayload($this, '7', ['issued_to' => 'Ward A']), $this->maker);

    expect(fn () => inventoryService()->issue(inventoryPayload($this, '7', ['issued_to' => 'Ward B']), $this->maker))->toThrow(ValidationException::class);

    $balance = InventoryBalance::query()->where('item_id', $this->item->id)->where('location_id', $this->location->id)->sole();
    expect($balance->quantity)->toBe('3.0000')
        ->and(inventoryService()->postedQuantity($this->item->id, $this->branch->id, $this->location->id))->toBe('3.0000')
        ->and(inventoryService()->balanceIsConsistent($balance))->toBeTrue()
        ->and(inventoryService()->inconsistentBalances())->toBeEmpty()
        ->and(InventoryMovement::query()->where('type', 'issue')->count())->toBe(1);
});

test('branch transfer keeps the total quantity and creates two linked legs', function () {
    $actor = inventoryUser(SystemRole::Accountant, $this->branch, $this->otherBranch);
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);

    $legs = inventoryService()->transfer([
        'item_id' => $this->item->id, 'from_branch_id' => $this->branch->id, 'from_location_id' => $this->location->id,
        'to_branch_id' => $this->otherBranch->id, 'to_location_id' => $this->otherLocation->id,
        'quantity' => '4', 'transaction_date' => '2026-01-16', 'reference' => 'TRF-1',
    ], $actor);

    expect($legs['out']->type)->toBe(InventoryMovementType::TransferOut)
        ->and($legs['out']->quantity)->toBe('-4.0000')
        ->and($legs['in']->type)->toBe(InventoryMovementType::TransferIn)
        ->and($legs['in']->quantity)->toBe('4.0000')
        ->and($legs['in']->branch_id)->toBe($this->otherBranch->id)
        ->and($legs['out']->transfer_group)->not->toBeNull()->toBe($legs['in']->transfer_group)
        ->and(InventoryMovement::query()->where('transfer_group', $legs['out']->transfer_group)->count())->toBe(2)
        ->and(inventoryBalance($this))->toBe('6.0000')
        ->and(inventoryBalance($this, $this->otherLocation))->toBe('4.0000')
        ->and((string) BigDecimal::sum(...InventoryBalance::query()->where('item_id', $this->item->id)->pluck('quantity')->all()))->toBe('10.0000')
        ->and(inventoryService()->inconsistentBalances())->toBeEmpty();

    $transfer = ['item_id' => $this->item->id, 'from_branch_id' => $this->branch->id, 'from_location_id' => $this->location->id, 'transaction_date' => '2026-01-16'];
    expect(fn () => inventoryService()->transfer([...$transfer, 'to_branch_id' => $this->otherBranch->id, 'to_location_id' => $this->otherLocation->id, 'quantity' => '6.0001'], $actor))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->transfer([...$transfer, 'to_branch_id' => $this->branch->id, 'to_location_id' => $this->location->id, 'quantity' => '1'], $actor))->toThrow(ValidationException::class)
        ->and(inventoryBalance($this))->toBe('6.0000')
        ->and(InventoryMovement::query()->whereNotNull('transfer_group')->count())->toBe(2);
});

test('transfer is refused without access to the destination branch', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    $transfer = [
        'item_id' => $this->item->id, 'from_branch_id' => $this->branch->id, 'from_location_id' => $this->location->id,
        'to_branch_id' => $this->otherBranch->id, 'to_location_id' => $this->otherLocation->id,
        'quantity' => '4', 'transaction_date' => '2026-01-16',
    ];

    expect(fn () => inventoryService()->transfer($transfer, $this->maker))->toThrow(HttpException::class);
    $this->post(route('inventory.transfer.store'), $transfer)->assertForbidden();

    expect(inventoryBalance($this))->toBe('10.0000')
        ->and(inventoryBalance($this, $this->otherLocation))->toBe('0.0000')
        ->and(InventoryMovement::query()->whereNotNull('transfer_group')->count())->toBe(0);
});

test('adjustment stays pending until a different user approves it', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    $adjustment = inventoryService()->requestAdjustment(inventoryPayload($this, '3', ['direction' => 'decrease', 'notes' => 'Damaged during storage']), $this->maker);

    expect($adjustment->status)->toBe(InventoryMovementStatus::Pending)
        ->and($adjustment->quantity)->toBe('-3.0000')
        ->and(inventoryBalance($this))->toBe('10.0000');

    // The maker cannot approve, even when holding the approval permission.
    $this->maker->givePermissionTo('inventory.approve_adjustment');
    expect(fn () => inventoryService()->approveAdjustment($adjustment, $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->approveAdjustment($adjustment, inventoryUser(SystemRole::Accountant, $this->branch)))->toThrow(HttpException::class)
        ->and(inventoryBalance($this))->toBe('10.0000');

    $approved = inventoryService()->approveAdjustment($adjustment, $this->approver);
    expect($approved->status)->toBe(InventoryMovementStatus::Posted)
        ->and($approved->approved_by)->toBe($this->approver->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and(inventoryBalance($this))->toBe('7.0000')
        ->and(inventoryService()->inconsistentBalances())->toBeEmpty()
        ->and(AuditLog::query()->where('event', 'inventory.adjustment_approved')->exists())->toBeTrue()
        ->and(fn () => inventoryService()->approveAdjustment($approved, $this->approver))->toThrow(ValidationException::class);

    expect(fn () => inventoryService()->requestAdjustment(inventoryPayload($this, '1', ['direction' => 'increase']), $this->maker))->toThrow(ValidationException::class);
});

test('approval re-checks stock and rejection leaves stock unchanged', function () {
    inventoryService()->receive(inventoryPayload($this, '5'), $this->maker);
    $decrease = inventoryService()->requestAdjustment(inventoryPayload($this, '4', ['direction' => 'decrease', 'notes' => 'Count difference']), $this->maker);
    $increase = inventoryService()->requestAdjustment(inventoryPayload($this, '2', ['direction' => 'increase', 'notes' => 'Found in store room']), $this->maker);
    inventoryService()->issue(inventoryPayload($this, '3', ['issued_to' => 'Ward A']), $this->maker);

    expect(fn () => inventoryService()->approveAdjustment($decrease, $this->approver))->toThrow(ValidationException::class)
        ->and($decrease->fresh()->status)->toBe(InventoryMovementStatus::Pending)
        ->and(inventoryBalance($this))->toBe('2.0000');

    $this->actingAs($this->approver)->post(route('inventory.adjustments.reject', $increase), ['reason' => 'Not supported by a count sheet'])->assertSessionHasNoErrors();
    $rejected = $increase->fresh();

    expect($rejected->status)->toBe(InventoryMovementStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Not supported by a count sheet')
        ->and(inventoryBalance($this))->toBe('2.0000')
        ->and(inventoryService()->inconsistentBalances())->toBeEmpty()
        ->and(AuditLog::query()->where('event', 'inventory.adjustment_rejected')->exists())->toBeTrue()
        ->and(fn () => inventoryService()->approveAdjustment($rejected, $this->approver))->toThrow(ValidationException::class);
});

test('return increases stock', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    inventoryService()->issue(inventoryPayload($this, '6', ['issued_to' => 'Speech Therapy']), $this->maker);
    $movement = inventoryService()->returnStock(inventoryPayload($this, '2.5', ['issued_to' => 'Speech Therapy', 'notes' => 'Unused items']), $this->maker);

    expect($movement->type)->toBe(InventoryMovementType::Return)
        ->and($movement->quantity)->toBe('2.5000')
        ->and($movement->issued_to)->toBe('Speech Therapy')
        ->and(inventoryBalance($this))->toBe('6.5000')
        ->and(AuditLog::query()->where('event', 'inventory.returned')->exists())->toBeTrue();
});

test('movements are immutable', function () {
    $movement = inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    $pending = inventoryService()->requestAdjustment(inventoryPayload($this, '1', ['direction' => 'increase', 'notes' => 'Found in store room']), $this->maker);

    expect(fn () => $movement->update(['quantity' => '99.0000']))->toThrow(LogicException::class)
        ->and(fn () => $movement->fresh()->update(['status' => 'rejected']))->toThrow(LogicException::class)
        ->and(fn () => $movement->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $pending->update(['quantity' => '50.0000']))->toThrow(LogicException::class)
        ->and(fn () => $pending->fresh()->update(['status' => 'posted', 'notes' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $pending->fresh()->delete())->toThrow(LogicException::class)
        ->and($movement->fresh()->quantity)->toBe('10.0000')
        ->and($pending->fresh()->quantity)->toBe('1.0000')
        ->and(InventoryMovement::query()->count())->toBe(2);
});

test('inactive item and inactive or foreign location are rejected', function () {
    $inactiveItem = InventoryItem::factory()->create(['is_active' => false]);
    $inactiveLocation = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);

    expect(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['item_id' => $inactiveItem->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['location_id' => $inactiveLocation->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['location_id' => $this->otherLocation->id]), $this->maker))->toThrow(ValidationException::class);
    $this->post(route('inventory.receive.store'), inventoryPayload($this, '1', ['item_id' => $inactiveItem->id]))->assertSessionHasErrors('item_id');

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryBalance::query()->count())->toBe(0);
});

test('cross branch viewing and acting are rejected', function () {
    $outsiderMaker = inventoryUser(SystemRole::Accountant, $this->otherBranch);
    $outsiderApprover = inventoryUser(SystemRole::Approver, $this->otherBranch);
    inventoryService()->receive(inventoryPayload($this, '10', ['reference' => 'GRN-PRIVATE']), $this->maker);
    $adjustment = inventoryService()->requestAdjustment(inventoryPayload($this, '1', ['direction' => 'decrease', 'notes' => 'Damaged during storage']), $this->maker);

    expect(fn () => inventoryService()->receive(inventoryPayload($this, '1'), $outsiderMaker))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->issue(inventoryPayload($this, '1', ['issued_to' => 'Ward A']), $outsiderMaker))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->approveAdjustment($adjustment, $outsiderApprover))->toThrow(HttpException::class);

    $this->actingAs($outsiderMaker)->post(route('inventory.issue.store'), inventoryPayload($this, '1', ['issued_to' => 'Ward A']))->assertForbidden();
    $this->actingAs($outsiderMaker)->put(route('inventory.locations.update', $this->location), ['name' => 'Taken over'])->assertForbidden();
    $this->actingAs($outsiderApprover)->post(route('inventory.adjustments.approve', $adjustment))->assertForbidden();
    $this->actingAs($outsiderApprover)->post(route('inventory.adjustments.reject', $adjustment), ['reason' => 'Not my branch'])->assertForbidden();

    $this->actingAs($outsiderMaker)->get(route('inventory.movements.index'))->assertOk()->assertDontSee('GRN-PRIVATE');
    $this->actingAs($outsiderMaker)->get(route('inventory.balances.index'))->assertOk()->assertDontSee($this->item->item_code);
    $this->actingAs($outsiderMaker)->get(route('inventory.balances.index', ['branch_id' => $this->branch->id]))->assertOk()->assertDontSee($this->item->item_code);
    $this->actingAs($outsiderMaker)->get(route('inventory.locations.index'))->assertOk()->assertDontSee($this->location->name);
    $this->actingAs($outsiderApprover)->get(route('inventory.adjustments.index'))->assertOk()->assertDontSee('Damaged during storage');
    $this->actingAs($this->maker)->get(route('inventory.movements.index'))->assertOk()->assertSee('GRN-PRIVATE');

    expect(inventoryBalance($this))->toBe('10.0000')
        ->and($adjustment->fresh()->status)->toBe(InventoryMovementStatus::Pending)
        ->and($this->location->fresh()->name)->not->toBe('Taken over');
});

test('unauthorized and inactive users are rejected', function () {
    $auditor = inventoryUser(SystemRole::Auditor, $this->branch);
    $inactive = inventoryUser(SystemRole::Accountant, $this->branch);
    $inactive->forceFill(['is_active' => false])->save();
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    $adjustment = inventoryService()->requestAdjustment(inventoryPayload($this, '1', ['direction' => 'decrease', 'notes' => 'Damaged during storage']), $this->maker);

    expect(fn () => inventoryService()->receive(inventoryPayload($this, '1'), $auditor))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->issue(inventoryPayload($this, '1', ['issued_to' => 'Ward A']), $auditor))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->returnStock(inventoryPayload($this, '1'), $auditor))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->requestAdjustment(inventoryPayload($this, '1', ['direction' => 'increase', 'notes' => 'Found in store room']), $auditor))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->approveAdjustment($adjustment, $auditor))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->rejectAdjustment($adjustment, 'Not allowed', $this->maker))->toThrow(HttpException::class)
        ->and(fn () => inventoryService()->receive(inventoryPayload($this, '1'), $inactive->fresh()))->toThrow(HttpException::class)
        ->and(fn () => app(InventoryCatalogService::class)->saveCategory(['name' => 'Blocked'], $auditor))->toThrow(HttpException::class);

    $this->actingAs($auditor)->post(route('inventory.receive.store'), inventoryPayload($this, '1'))->assertForbidden();
    $this->actingAs($auditor)->post(route('inventory.items.store'), ['item_code' => 'X-1', 'name' => 'Blocked', 'category_id' => $this->item->category_id, 'unit_of_measure' => 'pcs'])->assertForbidden();
    $this->actingAs($this->maker)->post(route('inventory.adjustments.approve', $adjustment))->assertForbidden();

    expect(inventoryBalance($this))->toBe('10.0000')
        ->and(InventoryItem::query()->where('item_code', 'X-1')->exists())->toBeFalse();
});

test('an approved purchase order alone creates no stock and its reference is validated on receipt', function () {
    $order = inventoryApprovedPurchaseOrder($this, $this->branch);
    $line = $order->lines()->first();

    expect($order->status)->toBe(PurchaseOrderStatus::Approved)
        ->and(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryBalance::query()->count())->toBe(0)
        ->and(inventoryBalance($this))->toBe('0.0000');

    $draft = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id, 'status' => 'draft']);
    $foreign = inventoryApprovedPurchaseOrder($this, $this->otherBranch);

    expect(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['purchase_order_id' => $draft->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['purchase_order_id' => $foreign->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['purchase_order_id' => $order->id, 'purchase_order_line_id' => $foreign->lines()->first()->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => inventoryService()->receive(inventoryPayload($this, '1', ['purchase_order_line_id' => $line->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(InventoryMovement::query()->count())->toBe(0);

    $this->post(route('inventory.receive.store'), inventoryPayload($this, '4', ['purchase_order_id' => $order->id, 'purchase_order_line_id' => $line->id]))->assertSessionHasNoErrors()->assertRedirect();
    $movement = InventoryMovement::query()->sole();

    expect($movement->purchase_order_id)->toBe($order->id)
        ->and($movement->purchase_order_line_id)->toBe($line->id)
        ->and(inventoryBalance($this))->toBe('4.0000')
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

test('low stock is detected per branch', function () {
    $this->item->update(['minimum_stock_level' => '5.0000']);
    $secondLocation = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $unmonitored = InventoryItem::factory()->create(['minimum_stock_level' => '0.0000']);
    inventoryService()->receive(inventoryPayload($this, '1', ['item_id' => $unmonitored->id]), $this->maker);

    // Never stocked in the branch: not reported.
    expect(inventoryService()->isLowStock($this->item, $this->branch))->toBeFalse();

    inventoryService()->receive(inventoryPayload($this, '4'), $this->maker);
    inventoryService()->receive(inventoryPayload($this, '4', ['location_id' => $secondLocation->id]), $this->maker);
    expect(inventoryService()->isLowStock($this->item, $this->branch))->toBeFalse()
        ->and(inventoryService()->lowStockQuery([$this->branch->id])->get())->toBeEmpty();

    inventoryService()->issue(inventoryPayload($this, '3', ['issued_to' => 'Ward A']), $this->maker);
    expect(inventoryService()->isLowStock($this->item, $this->branch))->toBeTrue()
        ->and(inventoryService()->isLowStock($unmonitored, $this->branch))->toBeFalse()
        ->and(inventoryService()->isLowStock($this->item, $this->otherBranch))->toBeFalse();

    $alerts = inventoryService()->lowStockQuery([$this->branch->id, $this->otherBranch->id])->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->item_id)->toBe($this->item->id)
        ->and($alerts[0]->branch_id)->toBe($this->branch->id);

    $this->get(route('inventory.low-stock.index'))->assertOk()->assertSee($this->item->item_code)->assertDontSee($unmonitored->item_code);
    $this->get(route('inventory.balances.index', ['low_stock' => 1]))->assertOk()->assertSee($this->item->item_code)->assertDontSee($unmonitored->item_code);
    $this->get(route('inventory.dashboard'))->assertOk()->assertSee('Low-stock alerts');
});

test('no journal entries or journal lines exist after all inventory activity', function () {
    $actor = inventoryUser(SystemRole::Accountant, $this->branch, $this->otherBranch);
    $order = inventoryApprovedPurchaseOrder($this, $this->branch);

    inventoryService()->receive(inventoryPayload($this, '20', ['purchase_order_id' => $order->id]), $this->maker);
    inventoryService()->issue(inventoryPayload($this, '5', ['issued_to' => 'Ward A']), $this->maker);
    inventoryService()->returnStock(inventoryPayload($this, '1', ['issued_to' => 'Ward A']), $this->maker);
    inventoryService()->transfer([
        'item_id' => $this->item->id, 'from_branch_id' => $this->branch->id, 'from_location_id' => $this->location->id,
        'to_branch_id' => $this->otherBranch->id, 'to_location_id' => $this->otherLocation->id, 'quantity' => '6', 'transaction_date' => '2026-01-16',
    ], $actor);
    $approved = inventoryService()->requestAdjustment(inventoryPayload($this, '2', ['direction' => 'decrease', 'notes' => 'Damaged during storage']), $this->maker);
    $rejected = inventoryService()->requestAdjustment(inventoryPayload($this, '3', ['direction' => 'increase', 'notes' => 'Found in store room']), $this->maker);
    inventoryService()->approveAdjustment($approved, $this->approver);
    inventoryService()->rejectAdjustment($rejected, 'No count sheet', $this->approver);

    expect(InventoryMovement::query()->count())->toBe(7)
        ->and(inventoryBalance($this))->toBe('8.0000')
        ->and(inventoryBalance($this, $this->otherLocation))->toBe('6.0000')
        ->and(inventoryService()->inconsistentBalances())->toBeEmpty()
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0);
});

test('master data is managed by authorized users', function () {
    $this->post(route('inventory.categories.store'), ['name' => 'Therapy Supplies'])->assertSessionHasNoErrors();
    $category = InventoryCategory::query()->where('name', 'Therapy Supplies')->sole();
    $this->post(route('inventory.categories.store'), ['name' => 'Therapy Supplies'])->assertSessionHasErrors('name');

    $this->post(route('inventory.items.store'), ['item_code' => 'THR-001', 'name' => 'Therapy putty', 'category_id' => $category->id, 'unit_of_measure' => 'box', 'minimum_stock_level' => '2.5'])->assertSessionHasNoErrors();
    $created = InventoryItem::query()->where('item_code', 'THR-001')->sole();
    expect($created->minimum_stock_level)->toBe('2.5000')
        ->and($created->created_by)->toBe($this->maker->id)
        ->and($created->is_active)->toBeTrue();
    $this->post(route('inventory.items.store'), ['item_code' => 'THR-001', 'name' => 'Duplicate', 'category_id' => $category->id, 'unit_of_measure' => 'box'])->assertSessionHasErrors('item_code');

    $this->post(route('inventory.locations.store'), ['branch_id' => $this->branch->id, 'name' => 'Main store'])->assertSessionHasNoErrors();
    $this->post(route('inventory.locations.store'), ['branch_id' => $this->branch->id, 'name' => 'Main store'])->assertSessionHasErrors('name');
    $this->post(route('inventory.locations.store'), ['branch_id' => $this->otherBranch->id, 'name' => 'Main store'])->assertForbidden();

    inventoryService()->receive(inventoryPayload($this, '1', ['item_id' => $created->id]), $this->maker);
    $this->put(route('inventory.items.update', $created), ['item_code' => 'THR-001', 'name' => 'Therapy putty', 'category_id' => $category->id, 'unit_of_measure' => 'kg'])->assertSessionHasErrors('unit_of_measure');
    $this->put(route('inventory.items.update', $created), ['item_code' => 'THR-001', 'name' => 'Therapy putty (soft)', 'category_id' => $category->id, 'unit_of_measure' => 'box', 'is_active' => '0'])->assertSessionHasNoErrors();

    expect($created->fresh()->name)->toBe('Therapy putty (soft)')
        ->and($created->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->whereIn('event', ['inventory_categories.created', 'inventory_items.created', 'inventory_items.updated', 'inventory_locations.created'])->count())->toBe(4);
});

test('screens render for an authorized user', function () {
    inventoryService()->receive(inventoryPayload($this, '10'), $this->maker);
    inventoryService()->requestAdjustment(inventoryPayload($this, '1', ['direction' => 'decrease', 'notes' => 'Damaged during storage']), $this->maker);

    foreach (['inventory.items.create', 'inventory.receive.create', 'inventory.issue.create', 'inventory.return.create', 'inventory.adjust.create', 'inventory.transfer.create'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->get(route('inventory.items.edit', $this->item))->assertOk()->assertSee($this->item->item_code);
    $this->get(route('inventory.dashboard'))->assertOk()->assertSee('Recent movements')->assertSee($this->item->item_code);
    $this->get(route('inventory.items.index', ['search' => $this->item->item_code, 'status' => 'active']))->assertOk()->assertSee($this->item->name);
    $this->get(route('inventory.categories.index'))->assertOk()->assertSee($this->item->category->name);
    $this->get(route('inventory.locations.index'))->assertOk()->assertSee($this->location->name);
    $this->get(route('inventory.balances.index', ['branch_id' => $this->branch->id]))->assertOk()->assertSee('10.0000');
    $this->get(route('inventory.movements.index', ['type' => 'receipt', 'from' => '2026-01-01', 'to' => '2026-12-31', 'search' => 'REF-1']))->assertOk()->assertSee('REF-1');
    $this->get(route('inventory.low-stock.index'))->assertOk();
    $this->actingAs($this->approver)->get(route('inventory.adjustments.index'))->assertOk()->assertSee('Damaged during storage')->assertSee('Approve');
    $this->actingAs(inventoryUser(SystemRole::Auditor, $this->branch))->get(route('inventory.dashboard'))->assertOk();
});

test('screens are forbidden for an unauthorized user', function () {
    $stranger = User::factory()->create();
    $stranger->branches()->attach($this->branch);

    foreach (['inventory.dashboard', 'inventory.items.index', 'inventory.items.create', 'inventory.categories.index', 'inventory.locations.index', 'inventory.balances.index', 'inventory.movements.index', 'inventory.low-stock.index', 'inventory.adjustments.index', 'inventory.receive.create', 'inventory.issue.create', 'inventory.return.create', 'inventory.adjust.create', 'inventory.transfer.create'] as $route) {
        $this->actingAs($stranger)->get(route($route))->assertForbidden();
    }

    $auditor = inventoryUser(SystemRole::Auditor, $this->branch);
    foreach (['inventory.items.create', 'inventory.receive.create', 'inventory.issue.create', 'inventory.return.create', 'inventory.adjust.create', 'inventory.transfer.create'] as $route) {
        $this->actingAs($auditor)->get(route($route))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('inventory.dashboard'))->assertRedirect(route('login'));
});
