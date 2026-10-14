<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Support\AppSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AppSetting::saved(fn (AppSetting $setting) => cache()->forget(AppSettings::cacheKey($setting->key)));
        AppSetting::deleted(fn (AppSetting $setting) => cache()->forget(AppSettings::cacheKey($setting->key)));

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        if (Schema::hasTable('app_settings')) {
            date_default_timezone_set(app(AppSettings::class)->string('app.timezone', (string) config('app.timezone')));
        }

        View::composer('*', function ($view): void {
            $settings = app(AppSettings::class);

            $view->with('appSettings', [
                'organization_name' => $settings->string('organization.name', (string) config('accounting.organization.name')),
                'organization_short_name' => $settings->string('organization.short_name', (string) config('accounting.organization.short_name')),
                'logo_path' => $settings->string('organization.logo_path', (string) config('accounting.organization.logo_path')),
                'currency_code' => $settings->string('currency.code', (string) config('accounting.currency.code')),
            ]);
        });
    }
}
