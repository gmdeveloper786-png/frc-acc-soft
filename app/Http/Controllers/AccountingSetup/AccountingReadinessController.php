<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Services\AccountingReadinessService;
use Illuminate\View\View;

class AccountingReadinessController extends Controller
{
    public function __invoke(AccountingReadinessService $readiness): View
    {
        $checks = collect($readiness->checks());

        return view('accounting-setup.readiness', [
            'checks' => $checks,
            'readyCount' => $checks->where('ready', true)->count(),
        ]);
    }
}
