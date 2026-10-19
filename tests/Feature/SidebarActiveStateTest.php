<?php

use App\Enums\SystemRole;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, DonationCategorySeeder::class]);
    $this->user = User::factory()->create();
    $this->user->assignRole(SystemRole::Administrator->value);
    $this->actingAs($this->user);
});

test('donation categories sidebar item is active on its page', function () {
    $this->get(route('donation-categories.index'))
        ->assertOk()
        ->assertSee('href="'.route('donation-categories.index').'" class="sidebar-link active"', false);
});

test('parameterized operational finance sidebar item activates only its matching type', function () {
    $response = $this->get(route('transactions.index', 'cash_receipt'))->assertOk();

    $response
        ->assertSee('href="'.route('transactions.index', 'cash_receipt').'" class="sidebar-link active"', false)
        ->assertSee('href="'.route('transactions.index', 'bank_receipt').'" class="sidebar-link "', false);
});

test('parameterized finance report sidebar item activates only its matching kind', function () {
    $response = $this->get(route('reports.books', 'cash'))->assertOk();

    $response
        ->assertSee('href="'.route('reports.books', 'cash').'" class="sidebar-link active"', false)
        ->assertSee('href="'.route('reports.books', 'bank').'" class="sidebar-link "', false);
});
