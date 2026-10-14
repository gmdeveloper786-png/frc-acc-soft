<?php

namespace App\Http\Controllers\Reports;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Services\Reports\Operational\InventoryReportService;
use App\Services\Reports\Operational\ReportBranchScope;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only, quantity-only inventory reports: nothing here writes stock.
 */
class InventoryReportController extends Controller
{
    public function index(): View
    {
        return view('reports.inventory.index', ['reports' => InventoryReportService::REPORTS]);
    }

    public function show(Request $request, InventoryReportService $service, ReportBranchScope $branchScope, string $report): View
    {
        $filters = $this->filters($request);
        $result = $service->build($report, $request->user(), $filters);
        $scope = $branchScope->resolve($request->user(), $filters['branch_id'] ?? null);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('reports.inventory.show', [
            'report' => $result, 'reports' => InventoryReportService::REPORTS, 'scope' => $scope, 'dated' => InventoryReportService::isDated($report),
            'rows' => new LengthAwarePaginator(array_slice($result->rows, ($page - 1) * 20, 20), count($result->rows), 20, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'items' => InventoryItem::query()->orderBy('item_code')->get(['id', 'item_code', 'name']),
            'categories' => InventoryCategory::query()->orderBy('name')->get(['id', 'name']),
            'locations' => InventoryLocation::query()->with('branch:id,name')->whereIn('branch_id', $scope['ids'])->orderBy('name')->get(),
            'types' => InventoryMovementType::cases(), 'statuses' => InventoryMovementStatus::cases(),
            'canExport' => $request->user()->can(InventoryReportService::EXPORT_PERMISSION),
        ]);
    }

    public function export(Request $request, InventoryReportService $service, ReportExporter $exporter, string $format, string $report): StreamedResponse
    {
        return $exporter->download($service->export($report, $request->user(), $this->filters($request))->toTable($format), $format, 'inventory-'.$report);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'regex:/^(all|\d{1,18})$/'], 'item_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'type' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'string', 'max:30'],
            'issued_to' => ['nullable', 'string', 'max:150'], 'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }
}
