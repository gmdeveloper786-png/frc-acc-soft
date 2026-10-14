<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\ApprovalDelegation;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalAuthorityService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = authUser(SystemRole::Accountant, $this->branch);
    $this->administrator = authUser(SystemRole::Administrator, $this->branch);
    $this->approver = authUser(SystemRole::Approver, $this->branch);
    $this->director = authUser(SystemRole::Director, $this->branch);
    $this->vendor = Vendor::factory()->create();
    $this->actingAs($this->maker);
});

function authUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** A submitted purchase order with exactly the given total. */
function submittedPo(object $test, string $total, ?User $maker = null): PurchaseOrder
{
    $maker ??= $test->maker;
    $service = app(PurchaseOrderService::class);
    $order = $service->create([
        'vendor_id' => $test->vendor->id, 'branch_id' => $test->branch->id, 'po_date' => '2026-01-10',
        'lines' => [['description' => 'Equipment', 'quantity' => '1.0000', 'unit_price' => $total]],
    ], $maker);

    return $service->submit($order, $maker)->fresh();
}

test('administrator holds every permission but not director approval authority', function () {
    $authority = app(ApprovalAuthorityService::class);

    expect($this->administrator->can('purchase_orders.approve_high_value'))->toBeTrue()
        ->and($authority->holdsDirectorRole($this->administrator))->toBeFalse()
        ->and($authority->holdsDirectorRole($this->approver))->toBeFalse()
        ->and($authority->holdsDirectorRole($this->director))->toBeTrue()
        ->and(Role::query()->whereNotNull('approval_authority')->pluck('name')->all())->toBe([SystemRole::Director->value]);
});

test('administrator cannot approve a purchase order above the threshold', function () {
    $order = submittedPo($this, '150000.0001');
    $service = app(PurchaseOrderService::class);

    expect($service->canApprove($order, $this->administrator))->toBeFalse()
        ->and(fn () => $service->approve($order, $this->administrator))->toThrow(HttpException::class);
    $this->actingAs($this->administrator)->post(route('purchase-orders.approve', $order))->assertForbidden();
    $this->actingAs($this->administrator)->get(route('purchase-orders.show', $order))->assertOk()->assertSee('Awaiting Director approval')->assertDontSee('data-bs-target="#approveModal">Approve', false);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($order->fresh()->approved_by)->toBeNull()
        ->and(AuditLog::query()->where('event', 'purchase_orders.approval_denied')->where('actor_id', $this->administrator->id)->exists())->toBeTrue();
});

test('ordinary approver cannot approve a purchase order above the threshold', function () {
    $order = submittedPo($this, '150000.0001');

    expect(fn () => app(PurchaseOrderService::class)->approve($order, $this->approver))->toThrow(HttpException::class);
    $this->actingAs($this->approver)->post(route('purchase-orders.approve', $order))->assertForbidden();
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});

test('director approves a purchase order above the threshold', function () {
    $order = submittedPo($this, '150000.0001');
    $this->actingAs($this->director)->get(route('purchase-orders.show', $order))->assertOk()->assertSee('data-bs-target="#approveModal">Approve', false);

    $approved = app(PurchaseOrderService::class)->approve($order, $this->director);
    expect($approved->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($approved->approved_by)->toBe($this->director->id)
        ->and($approved->approval_tier)->toBe('director')
        ->and(AuditLog::query()->where('event', 'purchase_orders.approved')->latest('id')->first()->properties['authority_basis'])->toBe('director_role')
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('exact threshold boundary decides who may approve', function () {
    $service = app(PurchaseOrderService::class);
    $atThreshold = submittedPo($this, '150000.0000');
    $aboveThreshold = submittedPo($this, '150000.0001');

    expect($service->canApprove($atThreshold, $this->administrator))->toBeTrue()
        ->and($service->canApprove($atThreshold, $this->approver))->toBeTrue()
        ->and($service->canApprove($aboveThreshold, $this->administrator))->toBeFalse()
        ->and($service->canApprove($aboveThreshold, $this->approver))->toBeFalse()
        ->and($service->canApprove($aboveThreshold, $this->director))->toBeTrue()
        ->and($service->approve($atThreshold, $this->administrator)->status)->toBe(PurchaseOrderStatus::Approved);

    AppSetting::query()->where('key', 'approval.purchase_order_threshold')->first()->update(['value' => '200000.00']);
    expect($service->canApprove($aboveThreshold, $this->approver))->toBeTrue();
    AppSetting::query()->where('key', 'approval.purchase_order_threshold')->first()->update(['value' => '100.00']);
    expect($service->canApprove($aboveThreshold, $this->approver))->toBeFalse()
        ->and(fn () => $service->approve($aboveThreshold, $this->administrator))->toThrow(HttpException::class);
});

test('director only policy removes administrator and approver authority below the threshold', function () {
    AppSetting::query()->where('key', 'approval.purchase_order_standard_policy')->first()->update(['value' => 'director_only']);
    $order = submittedPo($this, '500.0000');
    $service = app(PurchaseOrderService::class);

    expect(fn () => $service->approve($order, $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($order, $this->approver))->toThrow(HttpException::class)
        ->and($service->approve($order, $this->director)->status)->toBe(PurchaseOrderStatus::Approved);
});

test('maker checker applies to a director and to an administrator', function () {
    $service = app(PurchaseOrderService::class);
    $directorMaker = authUser(SystemRole::Director, $this->branch);
    $directorMaker->givePermissionTo(['purchase_orders.create', 'purchase_orders.submit']);
    $high = submittedPo($this, '150000.0001', $directorMaker);
    expect($service->canApprove($high, $directorMaker))->toBeFalse()
        ->and(fn () => $service->approve($high, $directorMaker))->toThrow(ValidationException::class)
        ->and($service->approve($high, $this->director)->status)->toBe(PurchaseOrderStatus::Approved);

    $low = submittedPo($this, '100.0000', $this->administrator);
    expect(fn () => $service->approve($low, $this->administrator))->toThrow(ValidationException::class)
        ->and($low->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});

test('granting the permission to a custom role does not create director authority', function () {
    $role = Role::query()->create(['name' => 'Custom High Value', 'guard_name' => 'web', 'approval_authority' => 'director']);
    $role->givePermissionTo(['purchase_orders.view', 'purchase_orders.approve', 'purchase_orders.approve_high_value']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->branches()->attach($this->branch);
    $order = submittedPo($this, '150000.0001');

    expect($role->fresh()->approval_authority)->toBeNull()
        ->and(fn () => app(PurchaseOrderService::class)->approve($order, $user))->toThrow(HttpException::class)
        ->and(fn () => Role::query()->where('name', SystemRole::Administrator->value)->first()->forceFill(['approval_authority' => 'director'])->save())->toThrow(LogicException::class);

    $this->actingAs($this->administrator)->put(route('roles.update', $role), ['name' => 'Custom High Value', 'max_branches' => null, 'approval_authority' => 'director', 'permissions' => ['purchase_orders.approve_high_value']])->assertRedirect();
    expect($role->fresh()->approval_authority)->toBeNull();
});

test('director authority respects branch access and invalid approval attempts', function () {
    $otherBranch = Branch::factory()->create(['status' => 'publish']);
    $outsideDirector = authUser(SystemRole::Director, $otherBranch);
    $order = submittedPo($this, '150000.0001');
    $service = app(PurchaseOrderService::class);

    expect($service->canApprove($order, $outsideDirector))->toBeFalse()
        ->and(fn () => $service->approve($order, $outsideDirector))->toThrow(HttpException::class);
    $this->actingAs($outsideDirector)->post(route('purchase-orders.approve', $order))->assertForbidden();

    $inactiveDirector = authUser(SystemRole::Director, $this->branch);
    $inactiveDirector->update(['is_active' => false]);
    expect(fn () => $service->approve($order, $inactiveDirector))->toThrow(HttpException::class);

    $service->approve($order, $this->director);
    expect(fn () => $service->approve($order->fresh(), $this->director))->toThrow(ValidationException::class);

    $draft = $service->create(['vendor_id' => $this->vendor->id, 'branch_id' => $this->branch->id, 'po_date' => '2026-01-10', 'lines' => [['description' => 'Equipment', 'quantity' => '1.0000', 'unit_price' => '200000.0000']]], $this->maker);
    expect(fn () => $service->approve($draft, $this->director))->toThrow(ValidationException::class)
        ->and($draft->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

test('only a director can delegate authority and only within strict limits', function () {
    AppSetting::query()->where('key', 'approval.delegation_enabled')->first()->update(['value' => '1']);
    $authority = app(ApprovalAuthorityService::class);
    $valid = ['reason' => 'Director on approved leave 1-5 Feb', 'ends_at' => now()->addDays(5)->toDateTimeString()];

    expect(fn () => $authority->delegate($this->approver, $valid, $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $authority->delegate($this->approver, $valid, $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $authority->delegate($this->director, $valid, $this->director))->toThrow(ValidationException::class)
        ->and(fn () => $authority->delegate($this->maker, $valid, $this->director))->toThrow(ValidationException::class)
        ->and(fn () => $authority->delegate($this->approver, [...$valid, 'reason' => 'short'], $this->director))->toThrow(ValidationException::class)
        ->and(fn () => $authority->delegate($this->approver, [...$valid, 'ends_at' => now()->addDays(15)->toDateTimeString()], $this->director))->toThrow(ValidationException::class)
        ->and(fn () => $authority->delegate($this->approver, [...$valid, 'ends_at' => now()->subDay()->toDateTimeString()], $this->director))->toThrow(ValidationException::class)
        ->and(ApprovalDelegation::query()->count())->toBe(0);
});

test('an explicit audited delegation lets the delegate approve within its limits only', function () {
    AppSetting::query()->where('key', 'approval.delegation_enabled')->first()->update(['value' => '1']);
    $authority = app(ApprovalAuthorityService::class);
    $service = app(PurchaseOrderService::class);
    $within = submittedPo($this, '180000.0000');
    $beyond = submittedPo($this, '250000.0000');

    $this->actingAs($this->director);
    $delegation = $authority->delegate($this->approver, ['reason' => 'Director on approved leave 1-5 Feb', 'ends_at' => now()->addDays(5)->toDateTimeString(), 'branch_id' => $this->branch->id, 'max_amount' => '200000.0000'], $this->director);
    expect(AuditLog::query()->where('event', 'approval_delegations.granted')->where('auditable_id', $delegation->id)->exists())->toBeTrue()
        ->and($service->canApprove($beyond, $this->approver))->toBeFalse()
        ->and($service->canApprove($within, $this->administrator))->toBeFalse();

    $this->actingAs($this->approver);
    $approved = $service->approve($within, $this->approver, 'Approved under delegation');
    expect($approved->status)->toBe(PurchaseOrderStatus::Approved)
        ->and($approved->approvals()->where('action', 'approved')->first()->comments)->toContain('Delegated Director authority delegation:'.$delegation->id)
        ->and(AuditLog::query()->where('event', 'purchase_orders.approved')->latest('id')->first()->properties['authority_basis'])->toBe('delegation:'.$delegation->id)
        ->and(fn () => $service->approve($beyond, $this->approver))->toThrow(HttpException::class);
});

test('expired revoked and orphaned delegations grant nothing', function () {
    AppSetting::query()->where('key', 'approval.delegation_enabled')->first()->update(['value' => '1']);
    $authority = app(ApprovalAuthorityService::class);
    $service = app(PurchaseOrderService::class);
    $order = submittedPo($this, '180000.0000');
    $delegation = $authority->delegate($this->approver, ['reason' => 'Director on approved leave 1-5 Feb', 'ends_at' => now()->addDays(2)->toDateTimeString()], $this->director);
    expect($service->canApprove($order, $this->approver))->toBeTrue();

    $this->travel(3)->days();
    expect($service->canApprove($order, $this->approver))->toBeFalse();
    $this->travelBack();

    expect(fn () => $authority->revoke($delegation, $this->administrator))->toThrow(HttpException::class);
    $authority->revoke($delegation, $this->director);
    expect($service->canApprove($order, $this->approver))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'approval_delegations.revoked')->exists())->toBeTrue();

    $second = $authority->delegate($this->approver, ['reason' => 'Director on approved leave 6-8 Feb', 'ends_at' => now()->addDays(2)->toDateTimeString()], $this->director);
    expect($service->canApprove($order, $this->approver))->toBeTrue();
    $this->director->syncRoles([SystemRole::Approver->value]);
    expect($service->canApprove($order->fresh(), $this->approver))->toBeFalse()
        ->and($second->fresh()->revoked_at)->toBeNull();
});
