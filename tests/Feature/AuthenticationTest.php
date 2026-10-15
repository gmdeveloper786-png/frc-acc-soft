<?php

use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

test('login succeeds with valid credentials', function () {
    $user = User::factory()->create(['password' => 'ValidPassword123!']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'ValidPassword123!',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('invalid login is rejected', function () {
    $user = User::factory()->create(['password' => 'ValidPassword123!']);

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('guests cannot access the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated active users can access the dashboard without fabricated balances', function () {
    $user = User::factory()->create();
    $permission = Permission::query()->create(['name' => 'dashboard.view', 'guard_name' => 'web', 'group_name' => 'Dashboard']);
    $user->givePermissionTo($permission);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Accounting Dashboard')
        ->assertSee('Amount unavailable')
        ->assertDontSee('PKR 0');
});

test('logout ends the authenticated session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('public registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

test('inactive users cannot log in or access protected pages', function () {
    $user = User::factory()->inactive()->create(['password' => 'ValidPassword123!']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'ValidPassword123!',
    ])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('every role lands on the dashboard after logging in directly', function (SystemRole $role) {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['password' => 'ValidPassword123!']);
    $user->assignRole($role->value);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'ValidPassword123!'])->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();
})->with([
    'Administrator' => [SystemRole::Administrator],
    'Accountant' => [SystemRole::Accountant],
    'Cashier' => [SystemRole::Cashier],
    'Approver' => [SystemRole::Approver],
    'Management' => [SystemRole::Management],
    'Auditor' => [SystemRole::Auditor],
]);

test('logging in after being sent away from a protected page still lands on the dashboard', function () {
    $this->seed(RolePermissionSeeder::class);
    $board = User::factory()->create(['password' => 'ValidPassword123!']);
    $board->assignRole(SystemRole::Management->value);

    $this->get(route('users.index'))->assertRedirect(route('login'));
    expect(session('url.intended'))->toEndWith('/users');

    $this->post(route('login.store'), ['email' => $board->email, 'password' => 'ValidPassword123!'])->assertRedirect(route('dashboard'));
    expect(session()->has('url.intended'))->toBeFalse();
    $this->get(route('dashboard'))->assertOk();

    // Authorization is unchanged: the page itself is still refused to this role.
    $this->get(route('users.index'))->assertForbidden();
});

test('a role allowed to open the remembered page is still sent to the dashboard first', function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->administrator()->create(['password' => 'ValidPassword123!']);
    $admin->assignRole(SystemRole::Administrator->value);

    $this->get(route('users.index'))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'ValidPassword123!'])->assertRedirect(route('dashboard'));
    $this->get(route('users.index'))->assertOk();
});
