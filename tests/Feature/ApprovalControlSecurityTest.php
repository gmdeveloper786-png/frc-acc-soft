<?php

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PurchaseBillStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\ApprovalDelegation;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PurchaseBill;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalAuthorityService;
use App\Services\ApprovalSettingsService;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = controlUser(SystemRole::Accountant, $this->branch);
    $this->administrator = controlUser(SystemRole::Administrator, $this->branch);
    $this->approver = controlUser(SystemRole::Approver, $this->branch);
    $this->director = controlUser(SystemRole::Director, $this->branch);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->actingAs($this->maker);
});

function controlUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function approvalControls(array $overrides = []): array
{
    return ['settings' => ['approval__purchase_order_threshold' => '150000.00', 'approval__payment_threshold' => '150000.00', 'approval__purchase_order_standard_policy' => 'authorized_approver', 'approval__delegation_max_days' => '14', 'approval__split_detection_days' => '7', 'assets__depreciation_policy_approved' => '0', 'assets__depreciation_convention' => 'full_month', 'assets__capitalization_threshold' => '0.00', 'payroll__calculation_policy_approved' => '0', 'fees__accounting_basis' => 'cash', 'fees__accounting_policy_approved' => '0', ...$overrides], 'reason' => 'Board resolution 7 revises the approval limits'];
}

/** A submitted outflow of the given type and amount. */
function submittedOutflow(object $test, OperationalTransactionType $type, string $amount, array $overrides = []): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $transaction = $service->create([
        'type' => $type->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-15', 'amount' => $amount,
        'financial_account_id' => $test->cash->id, 'contra_account_id' => $test->expense->id, 'narration' => 'Isolated outflow fixture', ...$overrides,
    ], $test->maker);

    return $service->submit($transaction, $test->maker)->fresh();
}

function thresholdValue(string $key): string
{
    return (string) AppSetting::query()->where('key', $key)->value('value');
}

// --- Threshold and policy settings ---------------------------------------------

test('administrator cannot change approval thresholds through any endpoint', function () {
    $this->actingAs($this->administrator);
    // The Administrator role no longer carries the Director-only permission; even granted directly it changes nothing.
    expect($this->administrator->can('approval_settings.manage'))->toBeFalse()->and($this->administrator->can('settings.update'))->toBeTrue();
    $this->administrator->givePermissionTo('approval_settings.manage');

    $this->put(route('approval-settings.update'), approvalControls(['approval__payment_threshold' => '99999999.00', 'approval__purchase_order_threshold' => '99999999.00']))->assertForbidden();
    $this->get(route('approval-settings.edit'))->assertForbidden();
    expect(fn () => app(ApprovalSettingsService::class)->update(['approval.payment_threshold' => '99999999.00'], 'Trying to raise my own limit', $this->administrator))->toThrow(HttpException::class);

    $general = collect(config('settings.fields'))->reject(fn ($field) => $field['type'] === 'file')->mapWithKeys(fn ($field, $key) => [str_replace('.', '__', $key) => thresholdValue($key)])->all();
    $this->put(route('settings.update'), ['settings' => [...$general, 'approval__payment_threshold' => '99999999.00', 'approval__purchase_order_standard_policy' => 'authorized_approver', 'approval__delegation_max_days' => '90']])->assertRedirect();
    $this->get(route('settings.edit'))->assertOk()->assertSee('Only a Director can change them')->assertDontSee('name="settings[approval__payment_threshold]"', false)->assertDontSee('Change approval controls');

    expect(thresholdValue('approval.payment_threshold'))->toBe('150000.00')
        ->and(thresholdValue('approval.purchase_order_threshold'))->toBe('150000.00')
        ->and(thresholdValue('approval.delegation_max_days'))->toBe('14')
        ->and(AuditLog::query()->where('event', 'approval_settings.changed')->count())->toBe(0);
});

test('approver and a permission without director authority cannot change thresholds', function () {
    $this->actingAs($this->approver)->put(route('approval-settings.update'), approvalControls(['approval__payment_threshold' => '500000.00']))->assertForbidden();
    $this->approver->givePermissionTo('approval_settings.manage');
    $this->actingAs($this->approver->fresh())->put(route('approval-settings.update'), approvalControls(['approval__payment_threshold' => '500000.00']))->assertForbidden();

    expect(thresholdValue('approval.payment_threshold'))->toBe('150000.00');
});

test('director changes thresholds with a reason and every change is audited with old and new values', function () {
    $this->actingAs($this->director)->get(route('approval-settings.edit'))->assertOk()->assertSee('Payment Director Approval Threshold');

    $this->put(route('approval-settings.update'), [...approvalControls(['approval__payment_threshold' => '200000.00']), 'reason' => 'short'])->assertSessionHasErrors('reason');
    $this->put(route('approval-settings.update'), approvalControls(['approval__payment_threshold' => '-5']))->assertSessionHasErrors('approval__payment_threshold');
    $this->put(route('approval-settings.update'), approvalControls(['approval__purchase_order_standard_policy' => 'anyone']))->assertSessionHasErrors('approval__purchase_order_standard_policy');
    expect(thresholdValue('approval.payment_threshold'))->toBe('150000.00');

    $this->put(route('approval-settings.update'), approvalControls(['approval__payment_threshold' => '200000.00', 'approval__purchase_order_standard_policy' => 'director_only']))->assertRedirect()->assertSessionHasNoErrors();
    $logs = AuditLog::query()->where('event', 'approval_settings.changed')->orderBy('id')->get();

    expect(thresholdValue('approval.payment_threshold'))->toBe('200000.00')
        ->and(thresholdValue('approval.purchase_order_standard_policy'))->toBe('director_only')
        ->and(thresholdValue('approval.purchase_order_threshold'))->toBe('150000.00')
        ->and($logs)->toHaveCount(2)
        ->and($logs->pluck('actor_id')->unique()->all())->toBe([$this->director->id])
        ->and($logs->firstWhere('properties.key', 'approval.payment_threshold')->properties)->toMatchArray(['old_value' => '150000.00', 'new_value' => '200000.00', 'reason' => 'Board resolution 7 revises the approval limits'])
        ->and($logs->first()->created_at)->not->toBeNull();
});

test('a raised threshold cannot be used by an administrator to approve what needed a director', function () {
    $payment = submittedOutflow($this, OperationalTransactionType::CashPayment, '180000.0000');
    $this->actingAs($this->administrator)->put(route('approval-settings.update'), approvalControls(['approval__payment_threshold' => '500000.00']))->assertForbidden();

    expect(fn () => app(OperationalTransactionService::class)->approve($payment, $this->administrator))->toThrow(HttpException::class)
        ->and($payment->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);
});

// --- Outflow bypass audit ------------------------------------------------------

test('every cash outflow above the threshold needs director approval', function (OperationalTransactionType $type, array $overrides) {
    $service = app(OperationalTransactionService::class);
    $transaction = submittedOutflow($this, $type, '150000.0001', $overrides);

    expect($transaction->approval_tier)->toBe('director')
        ->and($service->canApprove($transaction, $this->administrator))->toBeFalse()
        ->and($service->canApprove($transaction, $this->approver))->toBeFalse()
        ->and(fn () => $service->approve($transaction, $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($transaction, $this->approver))->toThrow(HttpException::class)
        ->and(fn () => $service->disburse($transaction->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->post($transaction->fresh(), $this->maker))->toThrow(ValidationException::class);

    $approved = $service->approve($transaction, $this->director);
    expect($approved->status)->toBe(OperationalTransactionStatus::Approved)
        ->and($approved->approved_by)->toBe($this->director->id)
        ->and(JournalEntry::query()->count())->toBe(0);
})->with([
    'cash payment' => [OperationalTransactionType::CashPayment, []],
    'paid expense' => [OperationalTransactionType::Expense, ['settlement_type' => 'paid']],
    'petty cash' => [OperationalTransactionType::PettyCash, []],
]);

test('outflows at the threshold and accrued expenses keep standard approval', function () {
    $service = app(OperationalTransactionService::class);
    $paid = submittedOutflow($this, OperationalTransactionType::Expense, '150000.0000', ['settlement_type' => 'paid']);
    $petty = submittedOutflow($this, OperationalTransactionType::PettyCash, '150000.0000');
    $accrued = submittedOutflow($this, OperationalTransactionType::Expense, '900000.0000', ['settlement_type' => 'accrued', 'financial_account_id' => null, 'payable_account_id' => $this->payable->id]);

    expect($paid->approval_tier)->toBe('standard')
        ->and($accrued->approval_tier)->toBe('standard')
        ->and($service->approve($paid, $this->approver)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and($service->approve($petty, $this->administrator)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and($service->approve($accrued, $this->approver)->status)->toBe(OperationalTransactionStatus::Approved);
});

test('vendor payment of a purchase bill above the threshold needs director approval', function () {
    $bill = PurchaseBill::factory()->create(['branch_id' => $this->branch, 'vendor_id' => Vendor::factory(), 'status' => 'posted', 'total_amount' => '400000.0000', 'created_by' => $this->maker]);
    $bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $service = app(OperationalTransactionService::class);
    $high = submittedOutflow($this, OperationalTransactionType::VendorPayment, '150000.0001', ['financial_account_id' => $bank->id, 'purchase_bill_id' => $bill->id, 'contra_account_id' => null]);
    $low = submittedOutflow($this, OperationalTransactionType::VendorPayment, '150000.0000', ['financial_account_id' => $bank->id, 'purchase_bill_id' => $bill->id, 'contra_account_id' => null]);

    expect(fn () => $service->approve($high, $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($high, $this->approver))->toThrow(HttpException::class)
        ->and($service->approve($high, $this->director)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and($service->approve($low, $this->approver)->status)->toBe(OperationalTransactionStatus::Approved);
});

test('a high value outflow can still be returned by an approver but never by its maker', function () {
    $service = app(OperationalTransactionService::class);
    $expense = submittedOutflow($this, OperationalTransactionType::Expense, '150000.0001', ['settlement_type' => 'paid']);

    $this->maker->givePermissionTo('expenses.approve');
    expect(fn () => $service->reject($expense, 'Maker cannot return it', $this->maker->fresh()))->toThrow(ValidationException::class)
        ->and($service->canReject($expense, $this->approver))->toBeTrue()
        ->and($service->reject($expense, 'Supporting invoice is missing', $this->approver)->status)->toBe(OperationalTransactionStatus::Rejected);
});

test('maker checker inactive users and other branches are refused for outflows', function () {
    $service = app(OperationalTransactionService::class);
    $expense = submittedOutflow($this, OperationalTransactionType::Expense, '500.0000', ['settlement_type' => 'paid']);
    $this->maker->givePermissionTo('expenses.approve');
    $outsider = controlUser(SystemRole::Approver, Branch::factory()->create(['status' => 'publish']));
    $inactive = controlUser(SystemRole::Approver, $this->branch);
    $inactive->update(['is_active' => false]);

    expect(fn () => $service->approve($expense, $this->maker->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => $service->approve($expense, $outsider))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($expense, $inactive))->toThrow(HttpException::class)
        ->and($expense->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);
});

// --- Director authority --------------------------------------------------------

test('one account cannot hold administrator and director roles together', function () {
    $authority = app(ApprovalAuthorityService::class);
    $this->director->givePermissionTo(['users.update', 'users.assign_roles', 'branches.assign_users']);
    $form = fn (User $user, array $roles) => ['name' => $user->name, 'email' => $user->email, 'password' => '', 'is_active' => '1', 'roles' => $roles, 'branches' => [(string) $this->branch->id], 'authority_reason' => 'Attempt to combine both roles'];
    $target = controlUser(SystemRole::Accountant, $this->branch);

    $this->actingAs($this->director)->put(route('users.update', $target), $form($target, [SystemRole::Administrator->value, SystemRole::Director->value]))->assertSessionHasErrors('roles');
    $this->put(route('users.update', $this->administrator), $form($this->administrator, [SystemRole::Administrator->value, SystemRole::Director->value]))->assertSessionHasErrors('roles');
    $this->artisan('app:director-authority', ['action' => 'grant', '--email' => $this->administrator->email, '--reason' => 'Owner letter appoints the administrator', '--database' => DB::connection()->getDatabaseName(), '--branch' => [$this->branch->id], '--authorized-by' => $this->director->email])
        ->expectsConfirmation("Grant Director approval authority for {$this->administrator->name} <{$this->administrator->email}>?", 'yes')->assertFailed();
    expect($this->administrator->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()
        ->and($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse();

    $this->administrator->roles()->attach(Role::query()->where('name', SystemRole::Director->value)->value('id'));
    $order = app(PurchaseOrderService::class)->create(['vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $this->branch->id, 'po_date' => '2026-01-10', 'lines' => [['description' => 'Equipment', 'quantity' => '1.0000', 'unit_price' => '150000.0001']]], $this->maker);
    app(PurchaseOrderService::class)->submit($order, $this->maker);
    expect($authority->holdsDirectorRole($this->administrator->fresh()))->toBeFalse()
        ->and(fn () => app(PurchaseOrderService::class)->approve($order->fresh(), $this->administrator->fresh()))->toThrow(HttpException::class);
});

test('administrator cannot alter the director role and role edits cannot grant authority', function () {
    $directorRole = Role::query()->where('name', SystemRole::Director->value)->firstOrFail();
    $before = $directorRole->permissions()->pluck('name')->sort()->values()->all();

    $this->actingAs($this->administrator)->get(route('roles.edit', $directorRole))->assertForbidden();
    $this->put(route('roles.update', $directorRole), ['name' => 'Director', 'permissions_submitted' => '1', 'permissions' => ['dashboard.view']])->assertForbidden();
    $this->post(route('roles.store'), ['name' => 'Shadow Director', 'approval_authority' => 'director', 'permissions' => ['purchase_orders.approve_high_value', 'payments.approve_high_value', 'approval_settings.manage']])->assertSessionHasErrors('permissions');
    $this->post(route('roles.store'), ['name' => 'Shadow Director', 'approval_authority' => 'director', 'permissions' => ['purchase_orders.approve']])->assertRedirect()->assertSessionHasNoErrors();

    expect($directorRole->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($before)
        ->and(Role::query()->where('name', 'Shadow Director')->value('approval_authority'))->toBeNull()
        ->and(Role::query()->whereNotNull('approval_authority')->count())->toBe(1);
});

test('delegation stays disabled and disabled records authorize nothing', function () {
    ApprovalDelegation::query()->create(['authority' => 'director', 'delegate_id' => $this->approver->id, 'delegated_by' => $this->director->id, 'reason' => 'Recorded before any policy approval', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    $expense = submittedOutflow($this, OperationalTransactionType::Expense, '150000.0001', ['settlement_type' => 'paid']);

    expect(thresholdValue('approval.delegation_enabled'))->toBe('0')
        ->and(app(ApprovalAuthorityService::class)->delegationEnabled())->toBeFalse()
        ->and(array_key_exists('approval.delegation_enabled', app(ApprovalSettingsService::class)->definitions()))->toBeFalse()
        ->and(fn () => app(OperationalTransactionService::class)->approve($expense, $this->approver))->toThrow(HttpException::class);

    $this->actingAs($this->director)->put(route('approval-settings.update'), [...approvalControls(), 'settings' => [...approvalControls()['settings'], 'approval__delegation_enabled' => '1']])->assertRedirect();
    expect(thresholdValue('approval.delegation_enabled'))->toBe('0');
});

// --- Purchase bill exceptions ------------------------------------------------------

test('a bill above its purchase order is approved only with director authority', function () {
    $orders = app(PurchaseOrderService::class);
    $bills = app(PurchaseBillService::class);
    $vendor = Vendor::factory()->create();
    $order = $orders->create(['vendor_id' => $vendor->id, 'branch_id' => $this->branch->id, 'po_date' => '2026-01-10', 'lines' => [['description' => 'Therapy mats', 'quantity' => '10.0000', 'unit_price' => '100.0000']]], $this->maker);
    $orders->submit($order, $this->maker);
    $orders->approve($order->fresh(), $this->approver);
    $payload = fn (string $number, string $quantity, array $extra = []) => ['vendor_id' => $vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $this->payable->id, 'bill_number' => $number, 'invoice_date' => '2026-01-15', 'purchase_order_id' => $order->id, 'lines' => [['account_id' => $this->expense->id, 'description' => 'Therapy mats', 'quantity' => $quantity, 'unit_amount' => '100.0000']], ...$extra];

    $within = $bills->submit($bills->create($payload('INV-W-1', '4.0000'), $this->maker), $this->maker);
    expect($bills->approve($within->fresh(), $this->administrator)->status)->toBe(PurchaseBillStatus::Approved);

    $over = $bills->submit($bills->create($payload('INV-X-1', '9.0000', ['po_exception_reason' => 'Vendor delivered three extra units accepted by the branch']), $this->maker), $this->maker);
    expect($this->administrator->can('purchase_bills.approve_po_exception'))->toBeFalse();
    $this->administrator->givePermissionTo('purchase_bills.approve_po_exception');
    expect(fn () => $bills->approve($over->fresh(), $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $bills->approve($over->fresh(), $this->approver))->toThrow(HttpException::class)
        ->and($over->fresh()->status)->toBe(PurchaseBillStatus::Submitted)
        ->and(AuditLog::query()->where('event', 'purchase_bills.po_exception_denied')->exists())->toBeTrue();

    $this->maker->givePermissionTo('purchase_bills.approve');
    expect(fn () => $bills->approve($within->fresh(), $this->maker->fresh()))->toThrow(ValidationException::class);

    $approved = $bills->approve($over->fresh(), $this->director);
    expect($approved->po_exception_status)->toBe('approved')
        ->and($approved->po_exception_approved_by)->toBe($this->director->id)
        ->and(JournalEntry::query()->count())->toBe(0);
});
