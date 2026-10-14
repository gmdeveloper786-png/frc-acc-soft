<?php

use App\Models\Permission;
use App\Models\User;

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
