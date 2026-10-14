<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Services\BranchAccess;
use App\Services\LedgerExports\LedgerReportTables;
use App\Services\Reports\ReportExporter;
use App\Services\TrialBalanceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrialBalanceController extends Controller
{
    public function __invoke(Request $request, BranchAccess $access, TrialBalanceService $trialBalance): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $years = FiscalYear::query()->orderByDesc('start_date')->get();
        $report = null;
        if ($filters = $this->filters($request, $branches)) {
            $report = $trialBalance->report($filters['fiscal_year']->id, $filters['as_of'], $filters['branch_ids']);
            $report['fiscal_year'] = $filters['fiscal_year'];
        }

        return view('accounting.reports.trial-balance', compact('branches', 'years', 'report'));
    }

    public function export(Request $request, string $format, BranchAccess $access, TrialBalanceService $trialBalance, LedgerReportTables $tables, ReportExporter $exporter): StreamedResponse|RedirectResponse
    {
        abort_unless($request->user()->can('trial_balance.view') && $request->user()->can('reports.export'), 403);
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $filters = $this->filters($request, $branches);
        if ($filters === null) {
            return redirect()->route('reports.trial-balance')->with('error', 'Generate the Trial Balance before exporting it.');
        }
        $report = $trialBalance->report($filters['fiscal_year']->id, $filters['as_of'], $filters['branch_ids']);
        if ($report['rows']->isEmpty()) {
            return redirect()->route('reports.trial-balance', $request->query())->with('error', 'Accounting setup required: the Chart of Accounts is empty, so there is nothing to export.');
        }

        return $exporter->download($tables->trialBalance($report, [
            'Fiscal year' => $filters['fiscal_year']->name,
            'As of' => $filters['as_of'],
            'Branch' => $filters['branch_id'] ? $branches->firstWhere('id', $filters['branch_id'])->name : 'All authorized branches',
        ]), $format, 'trial-balance-'.$filters['as_of']);
    }

    /**
     * Validated filters, shared by the screen and the export.
     *
     * @param  Collection<int, Branch>  $branches  Branches the user may see.
     * @return array{fiscal_year: FiscalYear, as_of: string, branch_ids: list<int>, branch_id: ?int}|null
     */
    private function filters(Request $request, Collection $branches): ?array
    {
        if (! $request->filled(['fiscal_year_id', 'as_of'])) {
            return null;
        }
        $validated = $request->validate(['fiscal_year_id' => ['required', 'exists:fiscal_years,id'], 'as_of' => ['required', 'date'], 'branch_id' => ['nullable', 'exists:branches,id']]);
        $year = FiscalYear::query()->findOrFail($validated['fiscal_year_id']);
        if ($validated['as_of'] < $year->start_date->toDateString() || $validated['as_of'] > $year->end_date->toDateString()) {
            throw ValidationException::withMessages(['as_of' => 'The report date must fall inside the selected fiscal year.']);
        }
        $allowedIds = $branches->pluck('id');
        $branchId = filled($validated['branch_id'] ?? null) ? (int) $validated['branch_id'] : null;
        $branchIds = $branchId ? [$branchId] : $allowedIds->all();
        abort_unless(collect($branchIds)->diff($allowedIds)->isEmpty(), 403);

        return ['fiscal_year' => $year, 'as_of' => $validated['as_of'], 'branch_ids' => $branchIds, 'branch_id' => $branchId];
    }
}
