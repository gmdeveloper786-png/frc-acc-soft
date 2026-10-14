<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\AppSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, DashboardService $dashboard, AppSettings $settings): View
    {
        return view('dashboard', [
            ...$dashboard->overview($request->user()),
            'openingDate' => $settings->string('fiscal.initial_opening_date', (string) config('accounting.initial_opening_date')),
            'dateFormat' => $settings->string('date.format', (string) config('accounting.date_format')),
            'integrationEnabled' => (bool) config('services.frc.enabled'),
            'integrationConfigured' => filled(config('services.frc.token')),
        ]);
    }
}
