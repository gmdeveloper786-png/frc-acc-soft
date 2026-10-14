<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFiscalYearRequest;
use App\Models\FiscalYear;
use App\Services\FiscalYearService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FiscalYearController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $fiscalYears = FiscalYear::query()->withCount('periods')->orderByDesc('start_date')->paginate(20);

        return view('accounting-setup.fiscal-years.index', compact('fiscalYears'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('accounting-setup.fiscal-years.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFiscalYearRequest $request, FiscalYearService $service): RedirectResponse
    {
        $year = $service->create($request->validated(), $request->user());

        return redirect()->route('fiscal-years.show', $year)->with('status', 'Fiscal year and monthly periods created.');
    }

    /**
     * Display the specified resource.
     */
    public function show(FiscalYear $fiscalYear): View
    {
        $fiscalYear->load(['periods.locker', 'creator', 'closer']);

        return view('accounting-setup.fiscal-years.show', compact('fiscalYear'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(FiscalYear $fiscalYear): View
    {
        return view('accounting-setup.fiscal-years.edit', compact('fiscalYear'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StoreFiscalYearRequest $request, FiscalYear $fiscalYear, FiscalYearService $service): RedirectResponse
    {
        $service->update($fiscalYear, $request->validated());

        return redirect()->route('fiscal-years.show', $fiscalYear)->with('status', 'Fiscal year updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function open(FiscalYear $fiscalYear, FiscalYearService $service): RedirectResponse
    {
        $service->open($fiscalYear);

        return back()->with('status', 'Fiscal year opened.');
    }

    public function close(FiscalYear $fiscalYear, FiscalYearService $service): RedirectResponse
    {
        $service->close($fiscalYear, request()->user());

        return back()->with('status', 'Fiscal year closed.');
    }

    public function reopen(FiscalYear $fiscalYear, FiscalYearService $service): RedirectResponse
    {
        $service->reopen($fiscalYear);

        return back()->with('status', 'Fiscal year reopened.');
    }

    public function current(FiscalYear $fiscalYear, FiscalYearService $service): RedirectResponse
    {
        $service->setCurrent($fiscalYear);

        return back()->with('status', 'Current fiscal year updated.');
    }
}
