<?php

namespace App\Http\Controllers\Reports;

use App\Enums\DepreciationRunStatus;
use App\Enums\FixedAssetStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Services\Reports\Operational\AssetReportService;
use App\Services\Reports\Operational\ReportBranchScope;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only fixed asset reports: nothing here posts or changes a record.
 */
class AssetReportController extends Controller
{
    public function index(Request $request): View
    {
        return view('reports.assets.index', ['reports' => $this->available($request)]);
    }

    public function show(Request $request, AssetReportService $service, ReportBranchScope $branchScope, string $report): View
    {
        $filters = $this->filters($request);
        $result = $service->build($report, $request->user(), $filters);
        $scope = $branchScope->resolve($request->user(), $filters['branch_id'] ?? null);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('reports.assets.show', [
            'report' => $result, 'reports' => $this->available($request), 'scope' => $scope,
            'rows' => new LengthAwarePaginator(array_slice($result->rows, ($page - 1) * 20, 20), count($result->rows), 20, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'categories' => AssetCategory::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => $report === 'history' ? [DepreciationRunStatus::Posted, DepreciationRunStatus::Reversed] : FixedAssetStatus::cases(),
            'assets' => $report === 'schedule' ? FixedAsset::query()->whereIn('branch_id', $scope['branches']->modelKeys())->orderBy('asset_code')->get(['id', 'asset_code', 'name']) : collect(),
            'periods' => $report === 'history' ? AccountingPeriod::query()->orderByDesc('start_date')->get(['id', 'name']) : collect(),
            'policyApproved' => $service->policyApproved(),
            'canExport' => $request->user()->can(AssetReportService::EXPORT_PERMISSION),
        ]);
    }

    public function export(Request $request, AssetReportService $service, ReportExporter $exporter, string $format, string $report): StreamedResponse
    {
        $result = $service->export($report, $request->user(), $this->filters($request));
        abort_if($result->prompt !== null, 404, $result->prompt ?? '');

        return $exporter->download($result->toTable($format), $format, 'asset-'.$report);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'regex:/^(all|\d{1,18})$/'], 'category_id' => ['nullable', 'integer'], 'asset_id' => ['nullable', 'integer'],
            'period_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:30'], 'search' => ['nullable', 'string', 'max:100'],
            'as_of' => ['nullable', 'date'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }

    /**
     * Reports the user holds the view permission for.
     *
     * @return array<string, array{title: string, permission: string, description: string}>
     */
    private function available(Request $request): array
    {
        return array_filter(AssetReportService::REPORTS, fn (array $definition): bool => $request->user()->can($definition['permission']));
    }
}
