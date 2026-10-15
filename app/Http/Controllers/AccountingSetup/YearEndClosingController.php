<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostYearEndClosingRequest;
use App\Models\FiscalYear;
use App\Services\YearEndClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class YearEndClosingController extends Controller
{
    /**
     * Preview the closing entries for the branches the user may see.
     */
    public function show(Request $request, FiscalYear $fiscalYear, YearEndClosingService $closing): View
    {
        $preview = $closing->preview($fiscalYear, $request->user());

        return view('accounting-setup.fiscal-years.closing', compact('fiscalYear', 'preview'));
    }

    /**
     * Post one closing journal per branch with an income or expenditure balance.
     */
    public function store(PostYearEndClosingRequest $request, FiscalYear $fiscalYear, YearEndClosingService $closing): RedirectResponse
    {
        $journals = $closing->post($fiscalYear, $request->user(), $request->string('reason')->toString());

        return redirect()->route('fiscal-years.closing', $fiscalYear)
            ->with('status', count($journals).' year-end closing '.str('journal')->plural(count($journals)).' posted.');
    }
}
