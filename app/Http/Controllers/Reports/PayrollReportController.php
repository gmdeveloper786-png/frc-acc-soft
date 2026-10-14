<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\Payroll\PayrollReportService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll reports. Authorization, branch scope and the removal of amount
 * columns all happen in PayrollReportService; this controller only passes the
 * request filters through and renders the table it is given.
 */
class PayrollReportController extends Controller
{
    private const FILTERS = ['branch_id', 'period_id', 'status', 'run_id', 'employee_id', 'import_id'];

    public function index(Request $request, PayrollReportService $reports): View
    {
        $user = $request->user();

        return view('reports.payroll.index', [
            'reports' => collect(PayrollReportService::REPORTS)->map(fn (string $title, string $key): array => [
                'key' => $key, 'title' => $title, 'available' => $reports->canOpen($user, $key), 'amountOnly' => in_array($key, PayrollReportService::AMOUNT_ONLY, true),
            ])->values(),
            'sensitive' => $reports->canViewSensitive($user),
        ]);
    }

    public function show(Request $request, string $report, PayrollReportService $reports): View
    {
        $user = $request->user();

        return view('reports.payroll.show', [...$reports->build($report, $user, $this->filters($request)), 'canExport' => $reports->canExport($user)]);
    }

    public function export(Request $request, string $report, string $format, PayrollReportService $reports, ReportExporter $exporter): StreamedResponse
    {
        return $exporter->download($reports->exportTable($report, $request->user(), $this->filters($request)), $format, 'payroll-'.$report);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $request->validate([
            'branch_id' => ['nullable', 'string', 'max:20'], 'period_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:20'],
            'run_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'], 'import_id' => ['nullable', 'integer'],
        ]);

        return $request->only(self::FILTERS);
    }
}
