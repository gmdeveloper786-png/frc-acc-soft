<?php

namespace App\Http\Controllers\Reports\CashFlow;

use App\Http\Controllers\Controller;
use App\Services\Reports\CashFlow\CashFlowStatementService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashFlowStatementController extends Controller
{
    use ResolvesCashReportFilters;

    public function show(Request $request, CashFlowStatementService $statements): View
    {
        $filters = $this->cashReportFilters($request);
        $statement = $filters['ready'] ? $statements->statement($request->user(), $filters['branch'], $filters['from'], $filters['to']) : null;

        return view('reports.cash-flow.statement', [...$filters, 'statement' => $statement]);
    }

    public function export(Request $request, string $format, CashFlowStatementService $statements, ReportExporter $exporter): StreamedResponse
    {
        abort_unless($request->user()->can('financial_statements.export'), 403);
        $filters = $this->cashReportFilters($request);
        abort_unless($filters['ready'], 422, 'Select a period and a branch before exporting.');
        $statement = $statements->statement($request->user(), $filters['branch'], $filters['from'], $filters['to']);

        return $exporter->download($statements->exportTable($statement, $filters['meta']), $format, 'cash-flow-statement');
    }
}
