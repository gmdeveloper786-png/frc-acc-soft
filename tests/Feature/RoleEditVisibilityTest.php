<?php

use App\Enums\SystemRole;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->administrator = roleScreenUser(SystemRole::Administrator, $this->branch);
    $this->directorRole = Role::findByName(SystemRole::Director->value);
    $this->administratorRole = Role::findByName(SystemRole::Administrator->value);
    $this->approverRole = Role::findByName(SystemRole::Approver->value);
});

function roleScreenUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

test('administrator sees view only for the director and administrator roles and edit for ordinary roles', function () {
    $index = $this->actingAs($this->administrator)->get(route('roles.index'))->assertOk()->assertSee('Carries Director approval authority')->getContent();

    expect($index)->toContain(route('roles.show', $this->directorRole))
        ->toContain(route('roles.show', $this->administratorRole))
        ->not->toContain(route('roles.edit', $this->directorRole))
        ->not->toContain(route('roles.edit', $this->administratorRole));
    foreach ([SystemRole::Accountant, SystemRole::Approver, SystemRole::Cashier, SystemRole::Auditor, SystemRole::Management] as $ordinary) {
        expect($index)->toContain(route('roles.edit', Role::findByName($ordinary->value)));
    }

    $this->get(route('roles.show', $this->directorRole))->assertOk()->assertDontSee(route('roles.edit', $this->directorRole), false);
    $this->get(route('roles.show', $this->administratorRole))->assertOk()->assertDontSee(route('roles.edit', $this->administratorRole), false);
    $this->get(route('roles.show', $this->approverRole))->assertOk()->assertSee(route('roles.edit', $this->approverRole), false);
});

test('direct and forged edits of the director and administrator roles are refused', function () {
    $directorPermissions = $this->directorRole->permissions()->pluck('name')->sort()->values()->all();
    $administratorPermissions = $this->administratorRole->permissions()->pluck('name')->sort()->values()->all();
    $this->actingAs($this->administrator);

    foreach ([$this->directorRole, $this->administratorRole] as $role) {
        $this->get(route('roles.edit', $role))->assertForbidden();
        $forged = ['name' => $role->name, 'max_branches' => '1', 'permissions_submitted' => '1', 'permissions' => ['dashboard.view', 'users.create']];
        $this->put(route('roles.update', $role), $forged)->assertForbidden();
        $this->patch(route('roles.update', $role), $forged)->assertForbidden();
    }

    expect($this->directorRole->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($directorPermissions)
        ->and($this->administratorRole->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($administratorPermissions)
        ->and($this->directorRole->fresh()->approval_authority)->toBe('director')
        ->and($this->directorRole->fresh()->max_branches)->toBeNull();

    // Director-only permissions still cannot be pushed onto an ordinary role, by PUT or PATCH.
    $before = $this->approverRole->permissions()->pluck('name')->all();
    $this->patch(route('roles.update', $this->approverRole), ['name' => $this->approverRole->name, 'permissions_submitted' => '1', 'permissions' => [...$before, 'payments.approve_high_value']])->assertSessionHasErrors('permissions');
    expect($this->approverRole->fresh()->hasPermissionTo('payments.approve_high_value'))->toBeFalse();
});

test('ordinary roles stay editable and an authorized director still manages the director role', function () {
    $before = $this->approverRole->permissions()->pluck('name')->all();
    $this->actingAs($this->administrator)->get(route('roles.edit', $this->approverRole))->assertOk();
    $this->patch(route('roles.update', $this->approverRole), ['name' => $this->approverRole->name, 'permissions_submitted' => '1', 'permissions' => [...$before, 'expenses.approve']])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->approverRole->fresh()->hasPermissionTo('expenses.approve'))->toBeTrue();

    $director = roleScreenUser(SystemRole::Director, $this->branch);
    $director->givePermissionTo(['roles.view', 'roles.update', 'roles.assign_permissions']);
    $this->actingAs($director)->get(route('roles.index'))->assertOk()->assertSee(route('roles.edit', $this->directorRole), false)->assertDontSee(route('roles.edit', $this->administratorRole), false);
    $this->get(route('roles.edit', $this->directorRole))->assertOk();
    $this->get(route('roles.edit', $this->administratorRole))->assertForbidden();
});
