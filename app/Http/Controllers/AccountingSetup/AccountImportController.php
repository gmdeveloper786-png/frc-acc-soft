<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadAccountImportRequest;
use App\Models\AccountImport;
use App\Services\AccountImportService;
use App\Services\AuditLogger;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountImportController extends Controller
{
    public function create(): View
    {
        $imports = AccountImport::query()->withCount('rows')->latest()->limit(20)->get();

        return view('accounting-setup.account-imports.create', compact('imports'));
    }

    public function store(UploadAccountImportRequest $request, AccountImportService $service): RedirectResponse
    {
        $import = $service->preview($request->file('workbook'), $request->user());

        return redirect()->route('account-imports.show', $import)->with('status', 'Workbook preview created. No accounts were imported.');
    }

    public function show(Request $request, AccountImport $accountImport, AccountImportService $service): View
    {
        $accountImport->refresh();
        $byCode = $accountImport->rows()->get()->keyBy('code');
        $rows = $accountImport->rows()
            ->when(in_array($request->string('classification')->toString(), ['shared', 'rehab', 'school_only', 'unclassified'], true), fn ($query) => $query->where('classification', $request->string('classification')))
            ->when(in_array($request->string('proposed')->toString(), ['include', 'review', 'exclude'], true), fn ($query) => $query->where('proposed_decision', $request->string('proposed')))
            ->when(in_array($request->string('review')->toString(), ['pending', 'approved', 'excluded'], true), fn ($query) => $query->where('review_status', $request->string('review')))
            ->when($request->string('flag')->toString() === 'manual', fn ($query) => $query->where('requires_manual_review', true))
            ->when($request->string('flag')->toString() === 'postable', fn ($query) => $query->where('is_postable', true))
            ->when($request->string('flag')->toString() === 'parents', fn ($query) => $query->where('is_postable', false))
            ->when($request->string('flag')->toString() === 'existing', fn ($query) => $query->where('is_existing', true))
            ->when($request->string('flag')->toString() === 'new', fn ($query) => $query->where('is_existing', false))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', $request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->paginate(50)->withQueryString();

        return view('accounting-setup.account-imports.show', [
            'accountImport' => $accountImport,
            'rows' => $rows,
            'paths' => $rows->getCollection()->mapWithKeys(fn ($row): array => [$row->id => $service->hierarchyPath($row, $byCode)]),
            'approvedCount' => $accountImport->rows()->where('review_status', 'approved')->where('is_existing', false)->count(),
        ]);
    }

    public function decide(Request $request, AccountImport $accountImport, AccountImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'], 'rows.*' => ['integer'],
            'decision' => ['required', 'in:approved,excluded,pending'], 'notes' => ['nullable', 'string', 'min:5', 'max:1000'],
        ]);
        $count = $service->decide($accountImport, array_map('intval', $data['rows']), $data['decision'], $data['notes'] ?? null, $request->user());

        return back()->with('status', "Review decision recorded for {$count} rows. No account was imported.");
    }

    public function export(AccountImport $accountImport, AccountImportService $service, AuditLogger $audit): StreamedResponse
    {
        $rows = $service->approvalSummary($accountImport);
        $audit->log('account_import.summary_exported', $accountImport, ['rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_keys($rows[0] ?? ['Account Code' => '']));
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection from workbook text.
                fputcsv($handle, array_map(fn (string $value): string => ReportExporter::safeText($value), $row));
            }
            fclose($handle);
        }, 'coa-approval-summary-'.$accountImport->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function confirm(Request $request, AccountImport $accountImport, AccountImportService $service): RedirectResponse
    {
        abort_unless($request->user()->can('accounts.import'), 403);
        $data = Validator::make($request->all(), ['rows' => ['nullable', 'array'], 'rows.*' => ['integer', 'exists:account_import_rows,id']])->validate();
        $created = $service->confirm($accountImport, array_map('intval', $data['rows'] ?? []), $request->user());

        return redirect()->route('accounts.index')->with('status', "{$created} accounts imported after review.");
    }
}
