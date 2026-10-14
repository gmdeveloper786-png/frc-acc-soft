<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Services\BranchAccess;
use App\Services\GeneralLedgerService;
use App\Services\LedgerExports\GeneralLedgerExportReader;
use App\Services\LedgerExports\LedgerReportTables;
use App\Services\Reports\ReportExporter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeneralLedgerController extends Controller
{
    public function __invoke(Request $request, BranchAccess $access, GeneralLedgerService $ledger): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $accounts = Account::query()->where('level', 'ledger')->orderBy('code')->get();
        $services = FrcService::query()->orderBy('name')->get();
        $years = FiscalYear::query()->orderByDesc('start_date')->get();
        $report = null;
        if ($filters = $this->filters($request, $branches)) {
            $report = $ledger->report($filters['account'], $filters['branch_ids'], $filters['from'], $filters['to'], $filters['service_id']);
            $report['account'] = $filters['account'];
            $report['fiscal_year'] = $filters['fiscal_year'];
        }

        return view('accounting.reports.general-ledger', compact('branches', 'accounts', 'services', 'years', 'report'));
    }

    /** Exports every line of the filtered range, not only the page on screen. */
    public function export(Request $request, string $format, BranchAccess $access, GeneralLedgerExportReader $reader, LedgerReportTables $tables, ReportExporter $exporter): StreamedResponse|RedirectResponse
    {
        abort_unless($request->user()->can('ledgers.view') && $request->user()->can('reports.export'), 403);
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $filters = $this->filters($request, $branches);
        if ($filters === null) {
            return redirect()->route('reports.general-ledger')->with('error', 'Generate the ledger before exporting it.');
        }
        $ledger = $reader->read($filters['account'], $filters['branch_ids'], $filters['from'], $filters['to'], $filters['service_id']);
        if ($ledger['rows'] === []) {
            return redirect()->route('reports.general-ledger', $request->query())->with('error', 'There is no posted activity in this range to export.');
        }

        return $exporter->download($tables->generalLedger($ledger, [
            'Account' => $filters['account']->code.' - '.$filters['account']->name,
            'Fiscal year' => $filters['fiscal_year']->name,
            'Period' => $filters['from'].' to '.$filters['to'],
            'Branch' => $filters['branch_id'] ? $branches->firstWhere('id', $filters['branch_id'])->name : 'All authorized branches',
            'Service' => $filters['service_id'] ? (string) FrcService::query()->whereKey($filters['service_id'])->value('name') : 'All activity',
        ]), $format, 'general-ledger-'.$filters['account']->code);
    }

    /**
     * Validated ledger filters, shared by the screen and the export so both read the same range.
     *
     * @param  Collection<int, Branch>  $branches  Branches the user may see.
     * @return array{account: Account, fiscal_year: FiscalYear, branch_ids: list<int>, branch_id: ?int, from: string, to: string, service_id: ?int}|null
     */
    private function filters(Request $request, Collection $branches): ?array
    {
        if (! $request->filled(['fiscal_year_id', 'account_id', 'from', 'to'])) {
            return null;
        }
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'], 'account_id' => ['required', 'exists:accounts,id'],
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'exists:branches,id'], 'service_id' => ['nullable', 'exists:frc_services,id'],
        ]);
        $year = FiscalYear::query()->findOrFail($validated['fiscal_year_id']);
        if ($validated['from'] < $year->start_date->toDateString() || $validated['to'] > $year->end_date->toDateString()) {
            throw ValidationException::withMessages(['from' => 'The ledger date range must fall inside the selected fiscal year.']);
        }
        $account = Account::query()->findOrFail($validated['account_id']);
        $allowedIds = $branches->pluck('id');
        $branchId = filled($validated['branch_id'] ?? null) ? (int) $validated['branch_id'] : null;
        $branchIds = $branchId ? [$branchId] : $allowedIds->all();
        abort_unless(collect($branchIds)->diff($allowedIds)->isEmpty(), 403);

        return [
            'account' => $account, 'fiscal_year' => $year, 'branch_ids' => $branchIds, 'branch_id' => $branchId,
            'from' => $validated['from'], 'to' => $validated['to'],
            'service_id' => isset($validated['service_id']) ? (int) $validated['service_id'] : null,
        ];
    }
}
