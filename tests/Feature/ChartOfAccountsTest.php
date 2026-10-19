<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
});

function validClassAccount(array $overrides = []): array
{
    return array_merge(['code' => '100000', 'name' => 'Assets', 'parent_id' => null, 'account_type' => 'asset', 'level' => 'class', 'normal_balance' => 'debit', 'financial_statement' => 'balance_sheet', 'is_postable' => '0', 'is_active' => '1', 'sort_order' => 1], $overrides);
}

test('account creation works with string code', function () {
    $this->actingAs($this->admin)->post(route('accounts.store'), validClassAccount(['code' => '001000']))->assertRedirect();
    $this->assertDatabaseHas('accounts', ['code' => '001000', 'name' => 'Assets']);
});

test('duplicate account code is rejected', function () {
    Account::factory()->create(['code' => '100000']);
    $this->actingAs($this->admin)->post(route('accounts.store'), validClassAccount())->assertSessionHasErrors('code');
});

test('hierarchy level and posting flags are validated', function () {
    $parent = Account::factory()->create(['code' => '100000', 'level' => 'class', 'is_postable' => false]);
    $this->actingAs($this->admin)->post(route('accounts.store'), validClassAccount(['code' => '110000', 'name' => 'Current Assets', 'level' => 'group', 'parent_id' => $parent->id, 'is_postable' => '1']))->assertSessionHasErrors('is_postable');
});

test('cash and bank eligibility can only be assigned to a valid posting ledger', function () {
    $this->actingAs($this->admin)->post(route('accounts.store'), validClassAccount(['financial_account_type' => 'cash']))
        ->assertSessionHasErrors('financial_account_type');
});

test('self parent and circular hierarchy are rejected', function () {
    $account = Account::factory()->create(['code' => '100000', 'level' => 'class', 'is_postable' => false]);
    $this->actingAs($this->admin)->put(route('accounts.update', $account), validClassAccount(['parent_id' => $account->id]))->assertSessionHasErrors('parent_id');
});

test('unauthorized account modification is rejected', function () {
    $this->actingAs(User::factory()->create())->post(route('accounts.store'), validClassAccount())->assertForbidden();
});
