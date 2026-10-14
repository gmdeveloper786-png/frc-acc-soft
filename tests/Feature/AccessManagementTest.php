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
