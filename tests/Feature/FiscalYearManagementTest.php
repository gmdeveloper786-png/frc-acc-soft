<?php

use App\Enums\SystemRole;
use App\Models\FiscalYear;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
});

test('authorized user creates a fiscal year with twelve monthly periods', function () {
    $this->actingAs($this->admin)->post(route('fiscal-years.store'), ['name' => 'FY 2031', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31'])->assertRedirect();
    $year = FiscalYear::query()->where('name', 'FY 2031')->firstOrFail();
    expect($year->periods()->count())->toBe(12)->and($year->periods()->first()->name)->toBe('January 2031');
});

test('overlapping fiscal years are rejected', function () {
    $this->actingAs($this->admin)->post(route('fiscal-years.store'), ['name' => 'FY 2031', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31']);
    $this->post(route('fiscal-years.store'), ['name' => 'Overlap', 'start_date' => '2031-06-01', 'end_date' => '2032-05-31'])->assertSessionHasErrors('start_date');
});

test('duplicate fiscal year name is rejected cleanly', function () {
    FiscalYear::factory()->create(['name' => 'FY 2030']);
    $this->actingAs($this->admin)->post(route('fiscal-years.store'), ['name' => 'FY 2030', 'start_date' => '2032-01-01', 'end_date' => '2032-12-31'])->assertSessionHasErrors('name');
});

test('only an open year can become current and only one current year remains', function () {
    $first = FiscalYear::factory()->create(['name' => 'FY 2030', 'start_date' => '2030-01-01', 'end_date' => '2030-12-31']);
    $second = FiscalYear::factory()->create(['name' => 'FY 2031', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'status' => 'open']);
    $this->actingAs($this->admin)->post(route('fiscal-years.current', $first))->assertSessionHasErrors('status');
    $this->post(route('fiscal-years.current', $second))->assertRedirect();
    expect(FiscalYear::query()->where('is_current', true)->count())->toBe(1);
});

test('unauthorized fiscal year access is rejected', function () {
    $this->actingAs(User::factory()->create())->get(route('fiscal-years.index'))->assertForbidden();
});
