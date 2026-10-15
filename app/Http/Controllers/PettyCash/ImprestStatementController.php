<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Http\Requests\PettyCash\ImprestStatementRequest;
use App\Models\ImprestFloat;
use App\Services\BranchAccess;
use App\Services\PettyCash\ImprestStatementService;
use App\Services\Reports\ReportExporter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImprestStatementController extends Controller
{
    public const EXPORT_PERMISSION = 'finance_reports.export';

    public function show(ImprestStatementRequest $request, ImprestFloat $float, BranchAccess $access, ImprestStatementService $statements): View
    {
        $this->authorizeBranch($request, $float, $access);
        [$from, $to] = $this->period($request);

        return view('imprest.statement', ['float' => $float, 'from' => $from, 'to' => $to, 'statement' => $statements->build($float, $from, $to)]);
    }

    public function export(ImprestStatementRequest $request, ImprestFloat $float, string $format, BranchAccess $access, ImprestStatementService $statements, ReportExporter $exporter): StreamedResponse
    {
        abort_unless($request->user()->can(self::EXPORT_PERMISSION), 403);
        $this->authorizeBranch($request, $float, $access);
        [$from, $to] = $this->period($request);
        $table = $statements->table($float, $from, $to, $statements->build($float, $from, $to));

        return $exporter->download($table, $format, 'imprest-statement-'.$float->id.'-'.$from->toDateString().'-'.$to->toDateString());
    }

    private function authorizeBranch(ImprestStatementRequest $request, ImprestFloat $float, BranchAccess $access): void
    {
        $float->load(['branch', 'account']);
        abort_unless($access->canAccess($request->user(), $float->branch), 403);
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} Defaults to the current month to date. */
    private function period(ImprestStatementRequest $request): array
    {
        $from = $request->filled('from') ? Carbon::createFromFormat('Y-m-d', $request->string('from')->toString()) : today()->startOfMonth();
        $to = $request->filled('to') ? Carbon::createFromFormat('Y-m-d', $request->string('to')->toString()) : today();

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
