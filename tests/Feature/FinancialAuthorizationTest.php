<?php

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
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
use App\Services\OperationalTransactionService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = financeUser(SystemRole::Accountant, $this->branch);
    $this->administrator = financeUser(SystemRole::Administrator, $this->branch);
    $this->approver = financeUser(SystemRole::Approver, $this->branch);
    $this->director = financeUser(SystemRole::Director, $this->branch);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->actingAs($this->maker);
});

function financeUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** A submitted cash payment of exactly the given amount. */
function submittedPayment(object $test, string $amount, ?User $maker = null): OperationalTransaction
{
    $maker ??= $test->maker;
    $service = app(OperationalTransactionService::class);
    $payment = $service->create([
        'type' => OperationalTransactionType::CashPayment->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-15',
        'amount' => $amount, 'financial_account_id' => $test->cash->id, 'contra_account_id' => $test->expense->id, 'narration' => 'Isolated payment fixture',
    ], $maker);

    return $service->submit($payment, $maker)->fresh();
}

function userForm(User $user, array $roles, array $overrides = []): array
{
    return ['name' => $user->name, 'email' => $user->email, 'password' => '', 'is_active' => '1', 'roles' => $roles, 'branches' => $user->branches->pluck('id')->map(fn ($id) => (string) $id)->all(), ...$overrides];
}

// --- High-value payment approval ---------------------------------------------

test('payment exactly at the threshold is approved by an authorized administrator or approver', function () {
    $service = app(OperationalTransactionService::class);
    $first = submittedPayment($this, '150000.0000');
    $second = submittedPayment($this, '150000.0000');

    expect($first->approval_tier)->toBe('standard')
        ->and($service->canApprove($first, $this->administrator))->toBeTrue()
        ->and($service->canApprove($first, $this->approver))->toBeTrue()
        ->and($service->approve($first, $this->administrator)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and($service->approve($second, $this->approver)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and(AuditLog::query()->where('event', 'payments.approved')->latest('id')->first()->properties['authority_basis'])->toBe('standard');
});

test('administrator and approver are denied approval of a payment above the threshold', function () {
    $service = app(OperationalTransactionService::class);
    $payment = submittedPayment($this, '150000.0001');

    expect($payment->approval_tier)->toBe('director')
        ->and($this->administrator->can('payments.approve_high_value'))->toBeFalse();
    // Even the permission granted directly is not Director authority.
    $this->administrator->givePermissionTo('payments.approve_high_value');
    expect($service->canApprove($payment, $this->administrator))->toBeFalse()
        ->and(fn () => $service->approve($payment, $this->administrator))->toThrow(HttpException::class)
        ->and(fn () => $service->approve($payment, $this->approver))->toThrow(HttpException::class);

    $this->actingAs($this->administrator)->post(route('transactions.approve', $payment))->assertForbidden();
    $this->actingAs($this->administrator)->get(route('transactions.show', $payment))->assertOk()->assertSee('Awaiting Director approval')->assertDontSee('Approve this transaction?');
    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Submitted)
        ->and($payment->fresh()->approved_by)->toBeNull()
        ->and(AuditLog::query()->where('event', 'payments.approval_denied')->where('actor_id', $this->administrator->id)->exists())->toBeTrue();
});

test('director approves a payment above the threshold without any posting', function () {
    $payment = submittedPayment($this, '150000.0001');
    $this->actingAs($this->director)->get(route('transactions.show', $payment))->assertOk()->assertSee('Approve this transaction?');

    $approved = app(OperationalTransactionService::class)->approve($payment, $this->director);
    expect($approved->status)->toBe(OperationalTransactionStatus::Approved)
        ->and($approved->approved_by)->toBe($this->director->id)
        ->and(AuditLog::query()->where('event', 'payments.approved')->latest('id')->first()->properties['authority_basis'])->toBe('director_role')
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('payment threshold is read from settings at approval time', function () {
    $service = app(OperationalTransactionService::class);
    $payment = submittedPayment($this, '5000.0000');
    expect($service->canApprove($payment, $this->approver))->toBeTrue();

    AppSetting::query()->where('key', 'approval.payment_threshold')->first()->update(['value' => '4999.99']);
    expect($service->canApprove($payment, $this->approver))->toBeFalse()
        ->and(fn () => $service->approve($payment, $this->administrator))->toThrow(HttpException::class)
        ->and($service->approve($payment, $this->director)->approval_tier)->toBe('director');
});

test('vendor payments above the threshold also need director authority', function () {
    $bill = PurchaseBill::factory()->create(['branch_id' => $this->branch, 'vendor_id' => Vendor::factory(), 'status' => 'posted', 'total_amount' => '500000.0000', 'created_by' => $this->maker]);
    $bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $service = app(OperationalTransactionService::class);
    $payment = $service->create(['type' => OperationalTransactionType::VendorPayment->value, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-15', 'amount' => '200000.0000', 'financial_account_id' => $bank->id, 'purchase_bill_id' => $bill->id, 'narration' => 'Vendor payment fixture'], $this->maker);
    $service->submit($payment, $this->maker);

    expect(fn () => $service->approve($payment->fresh(), $this->administrator))->toThrow(HttpException::class)
        ->and($service->approve($payment->fresh(), $this->director)->status)->toBe(OperationalTransactionStatus::Approved);
});

test('maker cannot approve their own payment at any amount', function () {
    $service = app(OperationalTransactionService::class);
    $directorMaker = financeUser(SystemRole::Director, $this->branch);
    $directorMaker->givePermissionTo(['payments.create', 'payments.submit']);
    $high = submittedPayment($this, '150000.0001', $directorMaker);
    $low = submittedPayment($this, '100.0000', $this->administrator);

    expect($service->canApprove($high, $directorMaker))->toBeFalse()
        ->and(fn () => $service->approve($high, $directorMaker))->toThrow(ValidationException::class)
        ->and(fn () => $service->approve($low, $this->administrator))->toThrow(ValidationException::class)
        ->and($high->fresh()->status)->toBe(OperationalTransactionStatus::Submitted)
        ->and($service->approve($high, $this->director)->status)->toBe(OperationalTransactionStatus::Approved);
});

test('cross branch payment approval is rejected even for a director', function () {
    $outsideDirector = financeUser(SystemRole::Director, Branch::factory()->create(['status' => 'publish']));
    $payment = submittedPayment($this, '150000.0001');

    expect(app(OperationalTransactionService::class)->canApprove($payment, $outsideDirector))->toBeFalse()
        ->and(fn () => app(OperationalTransactionService::class)->approve($payment, $outsideDirector))->toThrow(HttpException::class);
    $this->actingAs($outsideDirector)->post(route('transactions.approve', $payment))->assertForbidden();
    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);
});

// --- Director role assignment --------------------------------------------------

test('administrator cannot grant director authority to another account or a new account', function () {
    $target = financeUser(SystemRole::Accountant, $this->branch);

    $this->actingAs($this->administrator)->put(route('users.update', $target), userForm($target, [SystemRole::Accountant->value, SystemRole::Director->value], ['authority_reason' => 'Trying to appoint a director']))->assertSessionHasErrors('roles');
    $this->actingAs($this->administrator)->post(route('users.store'), [
        'name' => 'Shadow Director', 'email' => 'shadow@example.test', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
        'is_active' => '1', 'roles' => [SystemRole::Director->value], 'branches' => [$this->branch->id], 'authority_reason' => 'Trying to appoint a director',
    ])->assertSessionHasErrors('roles');

    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()
        ->and(User::query()->where('email', 'shadow@example.test')->exists())->toBeFalse()
        ->and(app(ApprovalAuthorityService::class)->holdsDirectorRole($target->fresh()))->toBeFalse();
});

test('administrator cannot escalate themselves or take over a director account', function () {
    $this->actingAs($this->administrator)->put(route('users.update', $this->administrator), userForm($this->administrator, [SystemRole::Administrator->value, SystemRole::Director->value], ['authority_reason' => 'Self appointment attempt']))->assertSessionHasErrors('roles');
    expect($this->administrator->fresh()->hasRole(SystemRole::Director->value))->toBeFalse();

    $originalPassword = $this->director->password;
    $this->put(route('users.update', $this->director), userForm($this->director, [SystemRole::Director->value], ['password' => 'TakeoverPassword123!', 'password_confirmation' => 'TakeoverPassword123!']))->assertForbidden();
    $this->put(route('users.update', $this->director), userForm($this->director, [SystemRole::Director->value], ['email' => 'attacker@example.test']))->assertForbidden();
    $this->put(route('users.update', $this->director), userForm($this->director, [SystemRole::Approver->value], ['authority_reason' => 'Removing the director']))->assertForbidden();

    expect($this->director->fresh()->password)->toBe($originalPassword)
        ->and($this->director->fresh()->email)->toBe($this->director->email)
        ->and($this->director->fresh()->hasRole(SystemRole::Director->value))->toBeTrue();
});

test('administrator keeps ordinary user administration but cannot change a director account', function () {
    $target = financeUser(SystemRole::Cashier, $this->branch);
    $this->actingAs($this->administrator)->put(route('users.update', $target), userForm($target, [SystemRole::Accountant->value]))->assertRedirect()->assertSessionHasNoErrors();
    $this->put(route('users.update', $this->director), userForm($this->director, [SystemRole::Director->value], ['name' => 'Renamed Director', 'is_active' => '0']))->assertForbidden();
    $this->put(route('users.update', $target), ['name' => $target->name, 'email' => $target->email, 'is_active' => '1', 'roles' => [SystemRole::Accountant->value]])->assertRedirect()->assertSessionHasNoErrors();

    expect($target->fresh()->hasRole(SystemRole::Accountant->value))->toBeTrue()
        ->and($target->fresh()->branches()->count())->toBe(0)
        ->and($this->director->fresh()->name)->not->toBe('Renamed Director')
        ->and($this->director->fresh()->is_active)->toBeTrue();
});

test('an existing director with the permission can appoint another director with a recorded reason', function () {
    $this->director->givePermissionTo(['users.update', 'users.assign_roles', 'branches.assign_users']);
    $target = financeUser(SystemRole::Approver, $this->branch);

    $this->actingAs($this->director)->put(route('users.update', $target), userForm($target, [SystemRole::Director->value]))->assertSessionHasErrors('authority_reason');
    $this->put(route('users.update', $target), userForm($target, [SystemRole::Director->value], ['authority_reason' => 'Board resolution 12 appoints a second Director']))->assertRedirect()->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('event', 'approval_authority.director_granted')->where('auditable_id', $target->id)->first();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeTrue()
        ->and($log->properties['reason'])->toBe('Board resolution 12 appoints a second Director')
        ->and($log->properties['source'])->toBe('user_management');

    Role::query()->where('name', SystemRole::Director->value)->first()->revokePermissionTo('approval_authority.assign');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->director->fresh())->put(route('users.update', $target), userForm($target, [SystemRole::Approver->value], ['authority_reason' => 'No longer permitted to manage']))->assertForbidden();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeTrue();
});

test('console provisioning appoints and removes a director only with confirmation and a reason', function () {
    $target = financeUser(SystemRole::Approver, $this->branch);
    $command = ['action' => 'grant', '--email' => $target->email, '--reason' => 'Owner letter dated 1 Jan appoints the Director', '--database' => DB::connection()->getDatabaseName(), '--branch' => [$this->branch->id], '--authorized-by' => $this->director->email];

    $this->artisan('app:director-authority', $command)->expectsConfirmation("Grant Director approval authority for {$target->name} <{$target->email}>?", 'no')->assertFailed();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse();
    $this->artisan('app:director-authority', [...$command, '--reason' => 'short'])->expectsConfirmation("Grant Director approval authority for {$target->name} <{$target->email}>?", 'yes')->assertFailed();
    $this->artisan('app:director-authority', [...$command, '--email' => 'nobody@example.test'])->assertFailed();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse();

    $this->artisan('app:director-authority', $command)->expectsConfirmation("Grant Director approval authority for {$target->name} <{$target->email}>?", 'yes')->assertSuccessful();
    $log = AuditLog::query()->where('event', 'approval_authority.director_granted')->where('auditable_id', $target->id)->first();
    expect(app(ApprovalAuthorityService::class)->holdsDirectorRole($target->fresh()))->toBeTrue()
        ->and($log->properties['source'])->toStartWith('console:');

    $this->artisan('app:director-authority', [...$command, 'action' => 'revoke'])->expectsConfirmation("Revoke Director approval authority for {$target->name} <{$target->email}>?", 'yes')->assertSuccessful();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'approval_authority.director_revoked')->where('auditable_id', $target->id)->exists())->toBeTrue();
});

// --- Delegation policy -----------------------------------------------------------

test('delegation is disabled by default and cannot be switched on from the settings screen', function () {
    $authority = app(ApprovalAuthorityService::class);

    expect($authority->delegationEnabled())->toBeFalse()
        ->and(array_key_exists('approval.delegation_enabled', config('settings.fields')))->toBeFalse()
        ->and(fn () => $authority->delegate($this->approver, ['reason' => 'Director on approved leave 1-5 Feb', 'ends_at' => now()->addDays(3)->toDateTimeString()], $this->director))->toThrow(ValidationException::class)
        ->and(ApprovalDelegation::query()->count())->toBe(0);
});

test('an existing delegation authorizes nothing while delegation is disabled', function () {
    ApprovalDelegation::query()->create(['authority' => 'director', 'delegate_id' => $this->approver->id, 'delegated_by' => $this->director->id, 'reason' => 'Recorded before the policy was approved', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    $payment = submittedPayment($this, '150000.0001');
    $order = app(PurchaseOrderService::class)->create(['vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $this->branch->id, 'po_date' => '2026-01-10', 'lines' => [['description' => 'Equipment', 'quantity' => '1.0000', 'unit_price' => '150000.0001']]], $this->maker);
    app(PurchaseOrderService::class)->submit($order, $this->maker);

    expect(app(OperationalTransactionService::class)->canApprove($payment, $this->approver))->toBeFalse()
        ->and(fn () => app(OperationalTransactionService::class)->approve($payment, $this->approver))->toThrow(HttpException::class)
        ->and(fn () => app(PurchaseOrderService::class)->approve($order->fresh(), $this->approver))->toThrow(HttpException::class)
        ->and(ApprovalDelegation::query()->count())->toBe(1);
});

test('once enabled only a valid unexpired delegation authorizes a high value payment', function () {
    AppSetting::query()->where('key', 'approval.delegation_enabled')->first()->update(['value' => '1']);
    $service = app(OperationalTransactionService::class);
    $payment = submittedPayment($this, '150000.0001');

    $expired = ApprovalDelegation::query()->create(['authority' => 'director', 'delegate_id' => $this->approver->id, 'delegated_by' => $this->director->id, 'reason' => 'Expired delegation fixture', 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]);
    ApprovalDelegation::query()->create(['authority' => 'director', 'delegate_id' => $this->approver->id, 'delegated_by' => $this->administrator->id, 'reason' => 'Delegation by a non director', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    ApprovalDelegation::query()->create(['authority' => 'director', 'delegate_id' => $this->approver->id, 'delegated_by' => $this->director->id, 'reason' => 'Revoked delegation fixture', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'revoked_at' => now()]);
    ApprovalDelegation::query()->create(['authority' => 'director', 'delegate_id' => $this->approver->id, 'delegated_by' => $this->director->id, 'reason' => 'Amount limit too low', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'max_amount' => '150000.0000']);
    expect($service->canApprove($payment, $this->approver))->toBeFalse()
        ->and(fn () => $service->approve($payment, $this->approver))->toThrow(HttpException::class)
        ->and($expired->fresh()->revoked_at)->toBeNull();

    $valid = app(ApprovalAuthorityService::class)->delegate($this->approver, ['reason' => 'Director on approved leave 1-5 Feb', 'ends_at' => now()->addDays(3)->toDateTimeString()], $this->director);
    $this->actingAs($this->approver);
    expect($service->approve($payment, $this->approver)->status)->toBe(OperationalTransactionStatus::Approved)
        ->and(AuditLog::query()->where('event', 'payments.approved')->latest('id')->first()->properties['authority_basis'])->toBe('delegation:'.$valid->id);
});

// --- Role update ---------------------------------------------------------------

test('role update without optional fields keeps existing values instead of failing', function () {
    $role = Role::query()->create(['name' => 'Custom Reviewer', 'guard_name' => 'web', 'max_branches' => 3]);
    $role->givePermissionTo(['reports.view', 'dashboard.view']);

    $this->actingAs($this->administrator)->put(route('roles.update', $role), ['name' => 'Custom Reviewer Renamed'])->assertRedirect()->assertSessionHasNoErrors();
    expect($role->fresh()->name)->toBe('Custom Reviewer Renamed')
        ->and($role->fresh()->max_branches)->toBe(3)
        ->and($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['dashboard.view', 'reports.view']);

    $this->put(route('roles.update', $role), ['name' => 'Custom Reviewer Renamed', 'max_branches' => '', 'permissions_submitted' => '1'])->assertRedirect()->assertSessionHasNoErrors();
    expect($role->fresh()->max_branches)->toBeNull()->and($role->fresh()->permissions)->toHaveCount(0);

    $this->put(route('roles.update', $role), ['name' => 'Custom Reviewer Renamed', 'max_branches' => 'many', 'permissions' => ['not.a.permission']])->assertSessionHasErrors(['max_branches', 'permissions.0']);
    $this->post(route('roles.store'), ['name' => 'Minimal Role'])->assertRedirect()->assertSessionHasNoErrors();
    expect(Role::query()->where('name', 'Minimal Role')->firstOrFail()->max_branches)->toBeNull();
});
