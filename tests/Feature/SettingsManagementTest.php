<?php

use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class]);
});

test('settings page requires permission', function () {
    $this->actingAs(User::factory()->create())->get(route('settings.edit'))->assertForbidden();
});

test('authorized user can render the dynamic settings page', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::query()->where('name', 'settings.view')->firstOrFail());
    $this->actingAs($user)->get(route('settings.edit'))->assertOk()->assertSee('PO Director Approval Threshold')->assertSee('Payment Director Approval Threshold');
});

function generalSettingsPayload(): array
{
    return collect(config('settings.fields'))->reject(fn ($field) => $field['type'] === 'file')->mapWithKeys(function ($field, $key) {
        return [str_replace('.', '__', $key) => AppSetting::query()->where('key', $key)->first()?->value ?? ''];
    })->all();
}

test('general settings validation rejects invalid values', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::query()->where('name', 'settings.update')->firstOrFail());
    $payload = generalSettingsPayload();
    $payload['currency__decimals'] = '9';
    $this->actingAs($user)->put(route('settings.update'), ['settings' => $payload])->assertSessionHasErrors('settings.currency__decimals');
});

test('general settings update never changes approval controls', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::query()->where('name', 'settings.update')->firstOrFail());
    $payload = generalSettingsPayload();
    $payload['organization__short_name'] = 'FRC2';
    $payload['approval__purchase_order_threshold'] = '175000.00';
    $payload['approval__payment_threshold'] = '99999999.00';
    $this->actingAs($user)->put(route('settings.update'), ['settings' => $payload])->assertRedirect()->assertSessionHasNoErrors();
    expect(AppSetting::where('key', 'organization.short_name')->value('value'))->toBe('FRC2')
        ->and(AppSetting::where('key', 'approval.purchase_order_threshold')->value('value'))->toBe('150000.00')
        ->and(AppSetting::where('key', 'approval.payment_threshold')->value('value'))->toBe('150000.00');
});
