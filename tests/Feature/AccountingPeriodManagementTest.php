<?php

use App\Enums\SystemRole;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AccountingPeriodService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
});

test('an open monthly period can be locked', function () {
    $year = FiscalYear::factory()->create(['status' => 'open']);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'status' => 'open']);
    $this->actingAs($this->admin)->post(route('accounting-periods.close', $period))->assertRedirect();
    expect($period->fresh()->status->value)->toBe('closed')->and($period->fresh()->locked_by)->toBe($this->admin->id);
});

test('closed period is unavailable for posting', function () {
    $year = FiscalYear::factory()->create(['status' => 'open']);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'status' => 'closed']);
    expect(app(AccountingPeriodService::class)->postingAllowed($period->fresh()))->toBeFalse();
});

test('unauthorized user cannot reopen a period', function () {
    $year = FiscalYear::factory()->create(['status' => 'open']);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'status' => 'closed']);
    $this->actingAs(User::factory()->create())->post(route('accounting-periods.reopen', $period))->assertForbidden();
});
