<?php

use App\Enums\SystemRole;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function milestoneAdmin(): User
{
    $user = User::factory()->administrator()->create();
    $user->assignRole(SystemRole::Administrator->value);

    return $user;
}

test('administrator can access administration pages', function () {
    $this->actingAs(milestoneAdmin())->get(route('users.index'))->assertOk();
    $this->get(route('roles.index'))->assertOk();
});

test('unauthorized user management access is rejected', function () {
    $this->actingAs(User::factory()->create())->get(route('users.index'))->assertForbidden();
});

test('authorized administrator can create a user with role and branch', function () {
    $admin = milestoneAdmin();
    $branch = Branch::factory()->create();

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'New Accountant', 'email' => 'accountant@example.test',
        'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
        'is_active' => '1', 'roles' => [SystemRole::Accountant->value], 'branches' => [$branch->id],
    ])->assertRedirect();

    $user = User::query()->where('email', 'accountant@example.test')->firstOrFail();
    expect($user->hasRole(SystemRole::Accountant->value))->toBeTrue()
        ->and($user->branches()->whereKey($branch)->exists())->toBeTrue()
        ->and($user->password)->not->toBe('StrongPassword123!');
});

test('user can be deactivated when another administrator remains active', function () {
    $admin = milestoneAdmin();
    $target = User::factory()->create();
    $target->assignRole(SystemRole::Accountant->value);

    $this->actingAs($admin)->put(route('users.update', $target), [
        'name' => $target->name, 'email' => $target->email, 'password' => '',
        'is_active' => '0', 'roles' => [SystemRole::Accountant->value], 'branches' => [],
    ])->assertRedirect();
    expect($target->fresh()->is_active)->toBeFalse();
});

test('role creation and permission assignment work', function () {
    $admin = milestoneAdmin();
    $this->actingAs($admin)->post(route('roles.store'), [
        'name' => 'Custom Reviewer', 'permissions' => ['reports.view', 'dashboard.view'], 'max_branches' => 2,
    ])->assertRedirect();
    $role = Role::query()->where('name', 'Custom Reviewer')->firstOrFail();
    expect($role->hasPermissionTo('reports.view'))->toBeTrue()->and($role->max_branches)->toBe(2);
});

test('role based routes use default deny', function () {
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Cashier->value);
    $this->actingAs($user)->get(route('branches.index'))->assertOk();
    $this->get(route('settings.edit'))->assertForbidden();
});

test('user cannot escalate their own roles', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::query()->whereIn('name', ['users.update', 'users.assign_roles', 'branches.assign_users'])->get());
    $this->actingAs($user)->put(route('users.update', $user), [
        'name' => $user->name, 'email' => $user->email, 'password' => '', 'is_active' => '1',
        'roles' => [SystemRole::Administrator->value], 'branches' => [],
    ])->assertSessionHasErrors('roles');
    expect($user->fresh()->hasRole(SystemRole::Administrator->value))->toBeFalse();
});

test('last active administrator cannot be deactivated', function () {
    $admin = milestoneAdmin();
    $other = User::factory()->create();
    $other->givePermissionTo(Permission::query()->whereIn('name', ['users.update', 'users.assign_roles', 'branches.assign_users', 'branches.view_all'])->get());
    $this->actingAs($other)->put(route('users.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email, 'password' => '', 'is_active' => '0',
        'roles' => [SystemRole::Administrator->value], 'branches' => [],
    ])->assertSessionHasErrors('roles');
    expect($admin->fresh()->is_active)->toBeTrue();
});

test('branch assignment and cross branch access are enforced server side', function () {
    $allowed = Branch::factory()->create();
    $blocked = Branch::factory()->create();
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Cashier->value);
    $user->branches()->attach($allowed);
    $this->actingAs($user)->get(route('branches.show', $allowed))->assertOk();
    $this->get(route('branches.show', $blocked))->assertForbidden();
});

test('explicit all branch permission authorizes every branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo(['branches.view', 'branches.view_all']);
    $this->actingAs($user)->get(route('branches.show', $branch))->assertOk();
});

/** A user manager without all-branch access, holding the Cashier role, scoped to one branch. */
function branchUserManager(Branch $branch): User
{
    $manager = User::factory()->create();
    $manager->assignRole(SystemRole::Cashier->value);
    $manager->givePermissionTo(['users.view', 'users.create', 'users.update', 'users.assign_roles', 'branches.assign_users', 'roles.view', 'roles.create', 'roles.update', 'roles.assign_permissions']);
    $manager->branches()->attach($branch);

    return $manager;
}

function userUpdateForm(User $user, array $roles, array $branches, array $overrides = []): array
{
    return ['name' => $user->name, 'email' => $user->email, 'password' => '', 'is_active' => '1', 'roles' => $roles, 'branches' => $branches, ...$overrides];
}

test('a user manager without all-branch access only sees and edits users of their own branches', function () {
    [$own, $foreign] = [Branch::factory()->create(), Branch::factory()->create()];
    $manager = branchUserManager($own);
    $colleague = User::factory()->create(['name' => 'Colleague Same Branch']);
    $colleague->assignRole(SystemRole::Accountant->value);
    $colleague->branches()->attach([$own->id, $foreign->id]);
    $outsider = User::factory()->create(['name' => 'Outsider Other Branch']);
    $outsider->assignRole(SystemRole::Cashier->value);
    $outsider->branches()->attach($foreign);

    $this->actingAs($manager)->get(route('users.index'))->assertOk()->assertSee('Colleague Same Branch')->assertDontSee('Outsider Other Branch');
    $this->get(route('users.show', $outsider))->assertForbidden();
    $this->get(route('users.edit', $outsider))->assertForbidden();
    $this->put(route('users.update', $outsider), userUpdateForm($outsider, [SystemRole::Cashier->value], [$own->id], ['is_active' => '0']))->assertForbidden();
    expect($outsider->fresh()->is_active)->toBeTrue();

    // Assigning a branch outside the manager's scope is refused; the colleague's out-of-scope branch is kept, not stripped.
    $this->put(route('users.update', $colleague), userUpdateForm($colleague, [SystemRole::Accountant->value], [$own->id, $foreign->id]))->assertForbidden();
    $this->get(route('users.edit', $colleague))->assertOk();
    // The colleague holds permissions the manager lacks, so their credentials cannot be taken over.
    $this->put(route('users.update', $colleague), userUpdateForm($colleague, [SystemRole::Accountant->value], [$own->id], ['password' => 'TakeoverPassword123!', 'password_confirmation' => 'TakeoverPassword123!']))->assertSessionHasErrors('email');
    $this->put(route('users.update', $colleague), userUpdateForm($colleague, [SystemRole::Accountant->value], [$own->id], ['name' => 'Colleague Renamed']))->assertRedirect()->assertSessionHasNoErrors();
    expect($colleague->fresh()->name)->toBe('Colleague Renamed')
        ->and($colleague->fresh()->branches()->pluck('branches.id')->sort()->values()->all())->toBe(collect([$own->id, $foreign->id])->sort()->values()->all());
});

test('a user manager can only grant roles and permissions they hold and never a protected role', function () {
    $branch = Branch::factory()->create();
    $manager = branchUserManager($branch);
    $target = User::factory()->create();
    $target->assignRole(SystemRole::Cashier->value);
    $target->branches()->attach($branch);
    $form = fn (array $roles): array => userUpdateForm($target, $roles, [$branch->id]);

    $this->actingAs($manager)->put(route('users.update', $target), $form([SystemRole::Accountant->value]))->assertSessionHasErrors('roles');
    $this->put(route('users.update', $target), $form([SystemRole::Administrator->value]))->assertSessionHasErrors('roles');
    $this->post(route('users.store'), ['name' => 'Escalated', 'email' => 'escalated@example.test', 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!', 'is_active' => '1', 'roles' => [SystemRole::Accountant->value], 'branches' => [$branch->id]])->assertSessionHasErrors('roles');
    expect($target->fresh()->getRoleNames()->all())->toBe([SystemRole::Cashier->value])
        ->and(User::query()->where('email', 'escalated@example.test')->exists())->toBeFalse();

    $this->post(route('roles.store'), ['name' => 'Shadow Poster', 'permissions' => ['journals.post', 'dashboard.view']])->assertSessionHasErrors('permissions');
    $role = Role::query()->create(['name' => 'Branch Helper', 'guard_name' => 'web']);
    $role->givePermissionTo('journals.post');
    $this->put(route('roles.update', $role), ['name' => 'Branch Helper', 'permissions_submitted' => '1', 'permissions' => ['journals.post', 'users.view', 'payments.approve']])->assertSessionHasErrors('permissions');
    expect(Role::query()->where('name', 'Shadow Poster')->exists())->toBeFalse()
        ->and($role->fresh()->permissions->pluck('name')->all())->toBe(['journals.post']);

    // Permissions the manager holds, and those the role already had, are fine.
    $this->put(route('roles.update', $role), ['name' => 'Branch Helper', 'permissions_submitted' => '1', 'permissions' => ['journals.post', 'users.view']])->assertRedirect()->assertSessionHasNoErrors();
    $this->put(route('users.update', $target), $form([SystemRole::Cashier->value]))->assertRedirect()->assertSessionHasNoErrors();
    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['journals.post', 'users.view']);
});

test('only an administrator can grant, remove or take over the administrator role', function () {
    $admin = milestoneAdmin();
    $manager = User::factory()->create();
    $manager->givePermissionTo(Permission::query()->where('name', '!=', 'approval_authority.assign')->get());
    $target = User::factory()->create();
    $target->assignRole(SystemRole::Accountant->value);
    $originalPassword = $admin->password;

    $this->actingAs($manager)->put(route('users.update', $target), userUpdateForm($target, [SystemRole::Administrator->value], []))->assertSessionHasErrors('roles');
    $this->get(route('users.edit', $admin))->assertForbidden();
    $this->put(route('users.update', $admin), userUpdateForm($admin, [SystemRole::Administrator->value], [], ['password' => 'TakeoverPassword123!', 'password_confirmation' => 'TakeoverPassword123!']))->assertSessionHasErrors('roles');
    expect($admin->fresh()->password)->toBe($originalPassword)
        ->and($target->fresh()->hasRole(SystemRole::Administrator->value))->toBeFalse();

    $this->actingAs($admin)->put(route('users.update', $target), userUpdateForm($target, [SystemRole::Administrator->value], []))->assertRedirect()->assertSessionHasNoErrors();
    expect($target->fresh()->hasRole(SystemRole::Administrator->value))->toBeTrue()
        ->and($target->fresh()->is_administrator)->toBeTrue();
});
