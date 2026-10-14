<?php

namespace App\Http\Controllers\Budgets;

use App\Enums\BudgetStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Services\BranchAccess;
use App\Services\Budgets\BudgetReportService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetReportController extends Controller
{
    public function versusActual(Request $request, BranchAccess $access, BudgetReportService $reports): View
    {
        $filters = $this->filters($request, $access, false);
        $selection = $this->versusActualSelection($request, $filters, $reports, true);

        return view('budgets.reports.versus-actual', [...$filters, ...$selection, 'missingBudget' => $selection['requested'] && $selection['report'] === null]);
    }

    public function exportVersusActual(Request $request, string $format, BranchAccess $access, BudgetReportService $reports, ReportExporter $exporter): StreamedResponse
    {
        $filters = $this->filters($request, $access, false);
        $selection = $this->versusActualSelection($request, $filters, $reports, false);
        abort_if($selection['report'] === null, 404, 'There is no approved budget for this selection to export.');

        return $exporter->download($reports->versusActualTable($selection['report'], $request->user(), $selection['layout'] === 'hierarchy'), $format, 'budget-vs-actual');
    }

    public function branchSummary(Request $request, BranchAccess $access, BudgetReportService $reports): View
    {
        $filters = $this->filters($request, $access, true);

        return view('budgets.reports.summary', [...$filters, 'rows' => $this->summaryRows($request, $filters, $reports, false, true), 'mode' => 'branch', 'warningPercentage' => $reports->warningPercentage()]);
    }

    public function serviceSummary(Request $request, BranchAccess $access, BudgetReportService $reports): View
    {
        $filters = $this->filters($request, $access, true);

        return view('budgets.reports.summary', [...$filters, 'rows' => $this->summaryRows($request, $filters, $reports, true, true), 'mode' => 'service', 'warningPercentage' => $reports->warningPercentage()]);
    }

    public function exportBranchSummary(Request $request, string $format, BranchAccess $access, BudgetReportService $reports, ReportExporter $exporter): StreamedResponse
    {
        return $this->exportSummary($request, $format, $access, $reports, $exporter, false);
    }

    public function exportServiceSummary(Request $request, string $format, BranchAccess $access, BudgetReportService $reports, ReportExporter $exporter): StreamedResponse
    {
        return $this->exportSummary($request, $format, $access, $reports, $exporter, true);
    }

    /** Lines at or above the warning threshold, plus the recorded alert log. */
    public function utilisation(Request $request, BranchAccess $access, BudgetReportService $reports): View
    {
        $filters = $this->filters($request, $access, true);
        $flagged = null;
        $alerts = null;
        $approvedCount = 0;
        if ($filters['year']) {
            $branchIds = $filters['selectedBranches']->pluck('id');
            $budgets = Budget::query()->with(['fiscalYear', 'branch', 'service'])->where('fiscal_year_id', $filters['year']->id)
                ->whereIn('branch_id', $branchIds)->where('status', BudgetStatus::Approved->value)->orderBy('branch_id')->orderBy('name')->get();
            $approvedCount = $budgets->count();
            $flagged = [];
            foreach ($budgets as $budget) {
                $report = $reports->versusActual($budget, $request->user(), $filters['from_period_id'], $filters['to_period_id']);
                foreach ($report['rows'] as $row) {
                    if ($row['status']->alertLevel() !== null) {
                        $flagged[] = [...$row, 'source' => $budget];
                    }
                }
            }
            $alerts = BudgetAlert::query()->with(['budget.branch', 'budget.service', 'account', 'accountingPeriod', 'detector'])
                ->whereHas('budget', fn ($query) => $query->where('fiscal_year_id', $filters['year']->id)->whereIn('branch_id', $branchIds))
                ->orderByDesc('detected_at')->orderByDesc('id')->paginate(20)->withQueryString();
        }

        return view('budgets.reports.utilisation', [...$filters, 'flagged' => $flagged, 'alerts' => $alerts, 'approvedCount' => $approvedCount, 'warningPercentage' => $reports->warningPercentage()]);
    }

    private function exportSummary(Request $request, string $format, BranchAccess $access, BudgetReportService $reports, ReportExporter $exporter, bool $serviceBudgets): StreamedResponse
    {
        $filters = $this->filters($request, $access, true);
        $rows = $this->summaryRows($request, $filters, $reports, $serviceBudgets, false);
        abort_if($rows === null, 404, 'Select a fiscal year to export.');
        $periods = $filters['periods'];
        $meta = [
            'Fiscal year' => $filters['year']->name,
            'Branch' => $request->filled('branch_id') ? $filters['selectedBranches']->pluck('name')->implode(', ') : ($request->user()->can('branches.view_all') ? 'All branches' : 'All branches assigned to the user'),
            ...($serviceBudgets ? ['Service' => $filters['service_id'] ? (string) $filters['services']->firstWhere('id', $filters['service_id'])?->name : 'All services'] : []),
            'Periods' => ($periods->firstWhere('id', $filters['from_period_id'])?->name ?? 'First period').' to '.($periods->firstWhere('id', $filters['to_period_id'])?->name ?? 'last period'),
        ];

        return $exporter->download($reports->summaryTable($rows, $request->user(), $serviceBudgets, $meta), $format, $serviceBudgets ? 'service-budget-summary' : 'branch-budget-summary');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return ?list<array{branch: Branch, budget: ?Budget, report: ?array<string, mixed>}>
     */
    private function summaryRows(Request $request, array $filters, BudgetReportService $reports, bool $serviceBudgets, bool $recordAlerts): ?array
    {
        if (! $filters['year']) {
            return null;
        }
        $rows = $reports->summary($filters['year'], $request->user(), $filters['selectedBranches'], $serviceBudgets, $filters['from_period_id'], $filters['to_period_id'], $recordAlerts);

        return $serviceBudgets
            ? collect($rows)->filter(fn (array $row): bool => $filters['service_id'] === null || $row['budget']?->service_id === $filters['service_id'])->values()->all()
            : $rows;
    }

    /**
     * The budget version to report and its figures. The approved version is
     * the default; a superseded version of the same fiscal year, branch and
     * service scope can be chosen instead. Any other budget id is refused.
     *
     * @param  array<string, mixed>  $filters
     * @return array{requested: bool, versions: Collection<int, Budget>, report: ?array<string, mixed>, layout: string}
     */
    private function versusActualSelection(Request $request, array $filters, BudgetReportService $reports, bool $recordAlerts): array
    {
        $validated = $request->validate([
            'budget_id' => ['nullable', 'integer'],
            'layout' => ['nullable', 'in:hierarchy,ledgers'],
        ]);
        $selection = ['requested' => false, 'versions' => collect(), 'report' => null, 'layout' => $validated['layout'] ?? 'hierarchy'];
        if (! $filters['year'] || ! $request->filled('branch_id')) {
            return $selection;
        }
        $versions = $reports->reportableVersions($filters['year']->id, $request->integer('branch_id'), $filters['service_id']);
        $budget = $versions->firstWhere('status', BudgetStatus::Approved);
        if (filled($validated['budget_id'] ?? null)) {
            $budget = $versions->firstWhere('id', (int) $validated['budget_id']);
            abort_if($budget === null, 404, 'That budget version is not an approved or superseded version of this fiscal year, branch and service.');
        }

        return [
            ...$selection, 'requested' => true, 'versions' => $versions,
            'report' => $budget ? $reports->versusActual($budget, $request->user(), $filters['from_period_id'], $filters['to_period_id'], $recordAlerts) : null,
        ];
    }

    /**
     * Validated report filters. A branch the user cannot access is refused
     * outright rather than silently ignored.
     *
     * @return array{years: Collection<int, FiscalYear>, branches: Collection<int, Branch>, services: Collection<int, FrcService>, year: ?FiscalYear, periods: Collection<int, mixed>, selectedBranches: Collection<int, Branch>, service_id: ?int, from_period_id: ?int, to_period_id: ?int}
     */
    private function filters(Request $request, BranchAccess $access, bool $defaultToCurrentYear): array
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'from_period_id' => ['nullable', 'integer', 'exists:accounting_periods,id'],
            'to_period_id' => ['nullable', 'integer', 'exists:accounting_periods,id'],
        ]);
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $selectedBranches = $branches;
        if (filled($validated['branch_id'] ?? null)) {
            abort_unless($branches->contains('id', (int) $validated['branch_id']), 403);
            $selectedBranches = $branches->where('id', (int) $validated['branch_id'])->values();
        }
        $years = FiscalYear::query()->orderByDesc('start_date')->get();
        $year = filled($validated['fiscal_year_id'] ?? null)
            ? $years->firstWhere('id', (int) $validated['fiscal_year_id'])
            : ($defaultToCurrentYear ? $years->firstWhere('is_current', true) : null);

        return [
            'years' => $years, 'branches' => $branches, 'services' => FrcService::query()->orderBy('name')->get(),
            'year' => $year, 'periods' => $year ? $year->periods()->get() : collect(), 'selectedBranches' => $selectedBranches,
            'service_id' => filled($validated['service_id'] ?? null) ? (int) $validated['service_id'] : null,
            'from_period_id' => filled($validated['from_period_id'] ?? null) ? (int) $validated['from_period_id'] : null,
            'to_period_id' => filled($validated['to_period_id'] ?? null) ? (int) $validated['to_period_id'] : null,
        ];
    }
}
