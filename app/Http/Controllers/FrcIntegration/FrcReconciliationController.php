<?php

namespace App\Http\Controllers\FrcIntegration;

use App\Http\Controllers\Controller;
use App\Services\Frc\FinancialSync\FrcReconciliationService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** FRC source payments against fee postings, for a date, branch and service scope. */
class FrcReconciliationController extends Controller
{
    use ResolvesFrcFilters;

    public function show(Request $request, FrcReconciliationService $reconciliation): View
    {
        $resolved = $this->frcFilters($request, paymentFilters: false);

        return view('frc-integration.reconciliation', [
            ...$resolved,
            'result' => $reconciliation->reconcile($request->user(), $resolved['filters']),
            'labels' => FrcReconciliationService::DIFFERENCE_LABELS,
            'note' => FrcReconciliationService::HISTORICAL_NOTE,
        ]);
    }

    public function export(Request $request, string $format, FrcReconciliationService $reconciliation, ReportExporter $exporter): StreamedResponse
    {
        abort_unless($request->user()->can('frc_integration.view') && $request->user()->can('financial_statements.export'), 403);
        $resolved = $this->frcFilters($request, paymentFilters: false);
        $result = $reconciliation->reconcile($request->user(), $resolved['filters'], rowLimit: null);

        return $exporter->download($reconciliation->exportTable($result, $resolved['meta']), $format, 'frc-fee-reconciliation');
    }
}
