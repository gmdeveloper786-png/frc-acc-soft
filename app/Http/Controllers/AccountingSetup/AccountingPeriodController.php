<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\AccountingPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountingPeriodController extends Controller
{
    public function index(): View
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get();
        $selectedYear = request('fiscal_year') ? $fiscalYears->firstWhere('id', (int) request('fiscal_year')) : $fiscalYears->firstWhere('is_current', true) ?? $fiscalYears->first();
        $periods = $selectedYear?->periods()->with('locker')->get() ?? collect();

        return view('accounting-setup.periods.index', compact('fiscalYears', 'selectedYear', 'periods'));
    }

    public function show(AccountingPeriod $accountingPeriod): View
    {
        $accountingPeriod->load(['fiscalYear', 'locker']);

        return view('accounting-setup.periods.show', compact('accountingPeriod'));
    }

    public function open(AccountingPeriod $accountingPeriod, AccountingPeriodService $service): RedirectResponse
    {
        $service->open($accountingPeriod);

        return back()->with('status', 'Accounting period opened.');
    }

    public function close(AccountingPeriod $accountingPeriod, AccountingPeriodService $service): RedirectResponse
    {
        $service->close($accountingPeriod, request()->user());

        return back()->with('status', 'Accounting period locked.');
    }

    public function reopen(AccountingPeriod $accountingPeriod, AccountingPeriodService $service): RedirectResponse
    {
        $service->reopen($accountingPeriod);

        return back()->with('status', 'Accounting period reopened.');
    }
}
