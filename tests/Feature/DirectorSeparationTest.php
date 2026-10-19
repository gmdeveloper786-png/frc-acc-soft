<?php

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalAuthorityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->administrator = separationUser(SystemRole::Administrator, $this->branch);
});

function separationUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** Console options shared by the provisioning tests. */
function provisionOptions(array $overrides = []): array
{
    return ['action' => 'grant', '--reason' => 'Owner letter FRC-2026-07 appoints the Director', '--database' => DB::connection()->getDatabaseName(), ...$overrides];
}

// --- Role and user administration -----------------------------------------------

test('administrator role carries no director only permission and director carries no system administration', function () {
    $administrator = Role::findByName(SystemRole::Administrator->value);
    $director = Role::findByName(SystemRole::Director->value);
    $adminOnly = ['users.create', 'users.update', 'users.assign_roles', 'roles.create', 'roles.update', 'roles.assign_permissions', 'settings.update', 'branches.assign_users', 'branches.view_all'];

    expect($administrator->permissions->pluck('name')->intersect(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS)->all())->toBe([])
        ->and($administrator->permissions()->count())->toBe(Permission::query()->count() - count(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS))
        ->and($director->permissions->pluck('name')->intersect($adminOnly)->all())->toBe([])
        ->and($director->permissions->pluck('name')->intersect(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS)->sort()->values()->all())->toBe(collect(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS)->sort()->values()->all());

    $directorUser = separationUser(SystemRole::Director, $this->branch);
    $this->actingAs($directorUser)->get(route('users.create'))->assertForbidden();
    $this->get(route('roles.create'))->assertForbidden();
    $this->post(route('users.store'), ['name' => 'Any', 'email' => 'any@example.test', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!', 'is_active' => '1', 'roles' => [SystemRole::Accountant->value]])->assertForbidden();
});

test('user forms offer the director role only to someone allowed to grant it', function () {
    $this->actingAs($this->administrator)->get(route('users.create'))->assertOk()
        ->assertSee('value="'.SystemRole::Accountant->value.'"', false)
        ->assertDontSee('value="'.SystemRole::Director->value.'"', false)
        ->assertDontSee('name="authority_reason"', false)
        ->assertSee('granted only by an existing Director or the Director provisioning procedure');

    $accountant = separationUser(SystemRole::Accountant, $this->branch);
    $this->get(route('users.edit', $accountant))->assertOk()->assertDontSee('value="'.SystemRole::Director->value.'"', false);

    $appointer = separationUser(SystemRole::Director, $this->branch);
    $appointer->givePermissionTo(['users.create', 'users.assign_roles', 'branches.assign_users']);
    $this->actingAs($appointer)->get(route('users.create'))->assertOk()
        ->assertSee('type="checkbox" name="roles[]" value="'.SystemRole::Director->value.'"', false)
        ->assertSee('name="authority_reason"', false);
});

test('administrator creates an ordinary accountant but cannot edit a director account', function () {
    $this->actingAs($this->administrator)->post(route('users.store'), [
        'name' => 'Branch Accountant', 'email' => 'accountant@example.test', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
        'is_active' => '1', 'roles' => [SystemRole::Accountant->value], 'branches' => [$this->branch->id],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $accountant = User::query()->where('email', 'accountant@example.test')->firstOrFail();
    expect($accountant->getRoleNames()->all())->toBe([SystemRole::Accountant->value]);

    $director = separationUser(SystemRole::Director, $this->branch);
    $this->put(route('users.update', $director), ['name' => 'Renamed Director', 'email' => $director->email, 'password' => '', 'is_active' => '1', 'roles' => [SystemRole::Director->value], 'branches' => [(string) $this->branch->id]])
        ->assertForbidden();
    expect($director->fresh()->name)->not->toBe('Renamed Director')->and($director->fresh()->hasRole(SystemRole::Director->value))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'like', 'approval_authority.%')->count())->toBe(0);
});

test('forged requests cannot make anyone a director through user management', function () {
    $accountant = separationUser(SystemRole::Accountant, $this->branch);
    $form = fn (User $user, array $roles) => ['name' => $user->name, 'email' => $user->email, 'password' => '', 'is_active' => '1', 'roles' => $roles, 'branches' => [(string) $this->branch->id], 'authority_reason' => 'Forged request with a long reason'];

    $this->actingAs($this->administrator)->post(route('users.store'), [
        'name' => 'Forged', 'email' => 'forged@example.test', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
        'is_active' => '1', 'roles' => [SystemRole::Accountant->value, SystemRole::Director->value], 'branches' => [$this->branch->id], 'authority_reason' => 'Forged request with a long reason',
    ])->assertSessionHasErrors('roles');
    $this->put(route('users.update', $accountant), $form($accountant, [SystemRole::Accountant->value, SystemRole::Director->value]))->assertSessionHasErrors('roles');
    $this->put(route('users.update', $this->administrator), $form($this->administrator, [SystemRole::Administrator->value, SystemRole::Director->value]))->assertSessionHasErrors('roles');

    expect(User::query()->where('email', 'forged@example.test')->exists())->toBeFalse()
        ->and(User::role(SystemRole::Director->value)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'approval_authority.director_granted')->exists())->toBeFalse();
});

test('administrator cannot add director only permissions to any role but still manages ordinary permissions', function () {
    $approver = Role::findByName(SystemRole::Approver->value);
    $before = $approver->permissions()->pluck('name')->sort()->values()->all();
    $form = fn (array $permissions) => ['name' => $approver->name, 'permissions_submitted' => '1', 'permissions' => $permissions];

    $this->actingAs($this->administrator)->get(route('roles.edit', $approver))->assertOk()->assertDontSee('value="payments.approve_high_value"', false)->assertSee('value="payments.approve"', false);
    $this->get(route('roles.create'))->assertOk()->assertDontSee('value="approval_authority.assign"', false);

    foreach (ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS as $permission) {
        $this->put(route('roles.update', $approver), $form([...$before, $permission]))->assertSessionHasErrors('permissions');
        $this->post(route('roles.store'), ['name' => 'Escalation '.$permission, 'permissions' => [$permission]])->assertSessionHasErrors('permissions');
    }
    expect($approver->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($before)
        ->and(Role::query()->where('name', 'like', 'Escalation%')->exists())->toBeFalse();

    $this->put(route('roles.update', $approver), $form([...$before, 'expenses.approve']))->assertRedirect()->assertSessionHasNoErrors();
    expect($approver->fresh()->hasPermissionTo('expenses.approve'))->toBeTrue();
});

// --- Console provisioning -------------------------------------------------------

test('first director is provisioned only with the right database branches and confirmation and is audited', function () {
    $target = separationUser(SystemRole::Approver, $this->branch);
    $second = Branch::factory()->create();
    $question = "Grant Director approval authority for {$target->name} <{$target->email}>?";
    $options = provisionOptions(['--email' => $target->email, '--branch' => [$this->branch->id, $second->id]]);

    $this->artisan('app:director-authority', [...$options, '--database' => 'some_other_database'])->assertFailed();
    $this->artisan('app:director-authority', [...$options, '--branch' => []])->assertFailed();
    $this->artisan('app:director-authority', [...$options, '--branch' => [999999]])->assertFailed();
    $this->artisan('app:director-authority', $options)->expectsConfirmation($question, 'no')->assertFailed();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()->and(AuditLog::query()->where('event', 'like', 'approval_authority.%')->exists())->toBeFalse();

    $this->artisan('app:director-authority', $options)->expectsConfirmation($question, 'yes')->assertSuccessful();
    $log = AuditLog::query()->where('event', 'approval_authority.director_granted')->where('auditable_id', $target->id)->firstOrFail();
    expect(app(ApprovalAuthorityService::class)->holdsDirectorRole($target->fresh()))->toBeTrue()
        ->and($target->fresh()->branches()->pluck('branches.id')->sort()->values()->all())->toBe(collect([$this->branch->id, $second->id])->sort()->values()->all())
        ->and($log->properties)->toMatchArray(['database' => DB::connection()->getDatabaseName(), 'environment' => app()->environment(), 'reason' => 'Owner letter FRC-2026-07 appoints the Director', 'branch_ids_before' => [$this->branch->id], 'authorized_by' => null])
        ->and($log->properties['source'])->toStartWith('console:');

    $this->artisan('app:director-authority', $options)->assertFailed();
});

test('another director needs an existing director to authorize it and an administrator can never be provisioned', function () {
    $sitting = separationUser(SystemRole::Director, $this->branch);
    $approver = separationUser(SystemRole::Approver, $this->branch);
    $target = separationUser(SystemRole::Approver, $this->branch);
    $options = provisionOptions(['--email' => $target->email, '--branch' => [$this->branch->id]]);

    $this->artisan('app:director-authority', $options)->assertFailed();
    $this->artisan('app:director-authority', [...$options, '--authorized-by' => $approver->email])->assertFailed();
    $this->artisan('app:director-authority', [...$options, '--authorized-by' => $this->administrator->email])->assertFailed();
    expect($target->fresh()->hasRole(SystemRole::Director->value))->toBeFalse();

    $this->artisan('app:director-authority', [...$options, '--authorized-by' => $sitting->email])
        ->expectsConfirmation("Grant Director approval authority for {$target->name} <{$target->email}>?", 'yes')->assertSuccessful();
    expect(AuditLog::query()->where('event', 'approval_authority.director_granted')->where('auditable_id', $target->id)->value('properties')['authorized_by'])->toBe($sitting->id);

    // Neither the Administrator role nor the legacy administrator flag can become a Director.
    $flagged = separationUser(SystemRole::Approver, $this->branch);
    $flagged->update(['is_administrator' => true]);
    foreach ([$this->administrator, $flagged] as $user) {
        $this->artisan('app:director-authority', provisionOptions(['--email' => $user->email, '--branch' => [$this->branch->id], '--authorized-by' => $sitting->email]))
            ->expectsConfirmation("Grant Director approval authority for {$user->name} <{$user->email}>?", 'yes')->assertFailed();
        expect($user->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()
            ->and($user->fresh()->branches()->pluck('branches.id')->all())->toBe([$this->branch->id]);
    }
});

test('a test director can be created with a secret password that is never printed or audited', function () {
    $password = 'Uat-Director-Pass-2026!';
    $other = separationUser(SystemRole::Accountant, $this->branch);

    $this->artisan('app:director-authority', provisionOptions(['--email' => 'test.director@example.test', '--create' => true, '--name' => 'TEST Director', '--branch' => [$this->branch->id], '--reason' => 'TEST Director for Phase 4 development only']))
        ->expectsQuestion('Password for the new user', $password)
        ->expectsQuestion('Confirm password', $password)
        ->expectsConfirmation('Grant Director approval authority for TEST Director <test.director@example.test>?', 'yes')
        ->doesntExpectOutputToContain($password)
        ->assertSuccessful();

    $director = User::query()->where('email', 'test.director@example.test')->firstOrFail();
    $log = AuditLog::query()->where('event', 'approval_authority.director_granted')->where('auditable_id', $director->id)->firstOrFail();
    expect($director->getRoleNames()->all())->toBe([SystemRole::Director->value])
        ->and($director->is_active)->toBeTrue()->and($director->is_administrator)->toBeFalse()
        ->and(password_verify($password, $director->password))->toBeTrue()
        ->and(json_encode($log->properties))->not->toContain($password)
        ->and($log->properties['created_user'])->toBeTrue()
        ->and($other->fresh()->getRoleNames()->all())->toBe([SystemRole::Accountant->value]);

    // A weak password creates nothing.
    $this->artisan('app:director-authority', provisionOptions(['--email' => 'weak@example.test', '--create' => true, '--name' => 'Weak', '--branch' => [$this->branch->id], '--authorized-by' => $director->email]))
        ->expectsQuestion('Password for the new user', 'short')->expectsQuestion('Confirm password', 'short')->assertFailed();
    expect(User::query()->where('email', 'weak@example.test')->exists())->toBeFalse();
});

test('console revocation removes director authority with an audit event', function () {
    $director = separationUser(SystemRole::Director, $this->branch);

    $this->artisan('app:director-authority', provisionOptions(['action' => 'revoke', '--email' => $director->email, '--reason' => 'Director term ended per board minute 9']))
        ->expectsConfirmation("Revoke Director approval authority for {$director->name} <{$director->email}>?", 'yes')->assertSuccessful();

    expect($director->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'approval_authority.director_revoked')->where('auditable_id', $director->id)->value('properties')['database'])->toBe(DB::connection()->getDatabaseName());
});

// --- Seeder ---------------------------------------------------------------------

test('role seeder is repeatable keeps assignments and custom roles and strips legacy director permissions from administrator', function () {
    $accountant = separationUser(SystemRole::Accountant, $this->branch);
    $custom = Role::query()->create(['name' => 'Branch Supervisor', 'guard_name' => 'web']);
    $custom->givePermissionTo(['dashboard.view', 'reports.view']);
    $accountant->givePermissionTo('budgets.approve');
    // An older release gave the Administrator role every permission.
    Role::findByName(SystemRole::Administrator->value)->givePermissionTo(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS);
    $counts = fn () => [Role::query()->count(), Permission::query()->count(), DB::table('role_has_permissions')->count(), DB::table('model_has_roles')->count()];

    $this->seed(RolePermissionSeeder::class);
    $first = $counts();
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($counts())->toBe($first)
        ->and(Role::query()->where('name', SystemRole::Director->value)->count())->toBe(1)
        ->and(Role::query()->whereNotNull('approval_authority')->pluck('name')->all())->toBe([SystemRole::Director->value])
        ->and(Role::findByName(SystemRole::Administrator->value)->permissions->pluck('name')->intersect(ApprovalAuthorityService::DIRECTOR_ONLY_PERMISSIONS)->all())->toBe([])
        ->and($this->administrator->fresh()->hasRole(SystemRole::Administrator->value))->toBeTrue()
        ->and($accountant->fresh()->getRoleNames()->all())->toBe([SystemRole::Accountant->value])
        ->and($accountant->fresh()->hasDirectPermission('budgets.approve'))->toBeTrue()
        ->and($custom->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['dashboard.view', 'reports.view']);
});
