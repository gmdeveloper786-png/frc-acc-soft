<?php

use App\Models\AppSetting;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Cache;

test('application settings cache scalar arrays instead of eloquent models', function () {
    AppSetting::factory()->create([
        'key' => 'test.setting',
        'value' => '42',
        'type' => 'integer',
    ]);

    $settings = app(AppSettings::class);

    expect($settings->integer('test.setting'))->toBe(42)
        ->and(Cache::get(AppSettings::cacheKey('test.setting')))->toBe([
            'type' => 'integer',
            'value' => '42',
        ]);
});

test('updating a setting invalidates its cached value', function () {
    $setting = AppSetting::factory()->create([
        'key' => 'test.setting',
        'value' => 'old',
        'type' => 'string',
    ]);

    $settings = app(AppSettings::class);
    expect($settings->string('test.setting'))->toBe('old');

    $setting->update(['value' => 'new']);

    expect($settings->string('test.setting'))->toBe('new');
});
