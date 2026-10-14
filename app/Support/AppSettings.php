<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AppSettings
{
    public function get(string $key, mixed $fallback = null): mixed
    {
        if (! Schema::hasTable('app_settings')) {
            return $fallback;
        }

        $setting = Cache::remember(
            self::cacheKey($key),
            now()->addMinutes(10),
            fn (): ?array => AppSetting::query()
                ->where('key', $key)
                ->first(['type', 'value'])
                ?->only(['type', 'value']),
        );

        if (! $setting) {
            return $fallback;
        }

        return match ($setting['type']) {
            'boolean' => filter_var($setting['value'], FILTER_VALIDATE_BOOL),
            'integer' => (int) $setting['value'],
            'decimal' => (string) $setting['value'],
            'json' => json_decode((string) $setting['value'], true),
            default => $setting['value'],
        };
    }

    public static function cacheKey(string $key): string
    {
        return "app-setting.v2.{$key}";
    }

    public function string(string $key, string $fallback = ''): string
    {
        return (string) $this->get($key, $fallback);
    }

    public function integer(string $key, int $fallback = 0): int
    {
        return (int) $this->get($key, $fallback);
    }
}
