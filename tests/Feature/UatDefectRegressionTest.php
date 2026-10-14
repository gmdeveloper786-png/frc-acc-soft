<?php

use App\Enums\SystemRole;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;

// Defects found during the browser UAT; see docs/UAT_EXECUTION_REPORT.md.

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
});

test('the sync buttons call window.confirm because the form has a field named confirm', function () {
    $html = $this->actingAs($this->admin)->get(route('frc-integration.index'))->assertOk()->getContent();

    expect($html)->toContain('name="confirm"')
        ->and(substr_count($html, 'return window.confirm('))->toBe(2)
        ->and($html)->not->toContain('return confirm(');
});

test('the error banner lists the reasons a request was refused', function () {
    $this->actingAs($this->admin)->from(route('frc-integration.index'))
        ->post(route('frc-integration.sync'), ['mode' => 'everything'])
        ->assertRedirect(route('frc-integration.index'));

    $this->actingAs($this->admin)->get(route('frc-integration.index'))
        ->assertOk()
        ->assertSeeInOrder(['Please review the highlighted information.', 'The selected mode is invalid.', 'The confirm field must be accepted.']);
});
