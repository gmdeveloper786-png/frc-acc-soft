<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadOpeningBalanceImportRequest;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceImport;
use App\Services\BranchAccess;
use App\Services\OpeningBalanceImportService;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpeningBalanceImportController extends Controller
{
    public function create(Request $request, BranchAccess $access, AppSettings $settings): View
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get();
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $openingDate = $settings->string('fiscal.initial_opening_date', '2026-01-01');

        return view('accounting-setup.opening-balance-imports.create', compact('fiscalYears', 'branches', 'openingDate'));
    }

    public function store(UploadOpeningBalanceImportRequest $request, OpeningBalanceImportService $service, BranchAccess $access): RedirectResponse
    {
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));
        abort_unless($access->canAccess($request->user(), $branch), 403);
        $import = $service->preview($request->file('workbook'), $request->safe()->except('workbook'), $request->user());

        return redirect()->route('opening-balance-imports.show', $import)->with('status', 'Import preview created. No balances were posted.');
    }

    public function show(Request $request, OpeningBalanceImport $openingBalanceImport, BranchAccess $access): View
    {
        abort_unless($access->canAccess($request->user(), $openingBalanceImport->branch), 403);
        $openingBalanceImport->load(['rows.account', 'branch', 'fiscalYear']);

        return view('accounting-setup.opening-balance-imports.show', compact('openingBalanceImport'));
    }

    public function apply(Request $request, OpeningBalanceImport $openingBalanceImport, OpeningBalanceImportService $service, BranchAccess $access): RedirectResponse
    {
        abort_unless($request->user()->can('opening_balances.import') && $access->canAccess($request->user(), $openingBalanceImport->branch), 403);
        $batch = $service->apply($openingBalanceImport, $request->user());

        return redirect()->route('opening-balances.show', $batch)->with('status', 'Import applied to draft preparation only; no journal posting occurred.');
    }
}
