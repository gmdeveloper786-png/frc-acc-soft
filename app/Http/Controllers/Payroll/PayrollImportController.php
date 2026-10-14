<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PayrollImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPayrollImportRequest;
use App\Models\AccountingPeriod;
use App\Models\PayrollComponent;
use App\Models\PayrollImport;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\Payroll\EmployeeService;
use App\Services\Payroll\PayrollImportService;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollImportController extends Controller
{
    public function index(Request $request, BranchAccess $access, PayrollRunService $runs): View
    {
        $branches = $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get();

        return view('payroll.imports.index', [
            'branches' => $branches, 'policyApproved' => $runs->policyApproved(),
            'periods' => AccountingPeriod::query()->where('status', AccountingPeriodStatus::Open->value)->whereHas('fiscalYear', fn ($query) => $query->where('status', FiscalYearStatus::Open->value))->orderByDesc('start_date')->get(),
            'imports' => PayrollImport::query()->with(['branch', 'period', 'creator'])->whereIn('branch_id', $access->scope($request->user())->pluck('id'))->latest('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function store(UploadPayrollImportRequest $request, PayrollImportService $service): RedirectResponse
    {
        $import = $service->upload($request->file('workbook'), $request->integer('branch_id'), $request->integer('accounting_period_id'), $request->user());

        return redirect()->route('payroll-imports.show', $import)->with('status', 'File stored for preview. Nothing was imported.');
    }

    /** Preview of the detected header and first rows, with the column mapping form. */
    public function show(Request $request, PayrollImport $payrollImport, BranchAccess $access, PayrollImportService $service, EmployeeService $employees): View
    {
        $this->guardBranch($request, $payrollImport, $access);
        $sensitive = $employees->canViewSensitive($request->user());
        $open = $payrollImport->status->isOpen();
        $components = PayrollComponent::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return view('payroll.imports.show', [
            'import' => $payrollImport->load(['branch', 'period', 'creator']), 'sensitive' => $sensitive, 'components' => $components,
            // Cell values are salary data: without payroll.view_sensitive only the headings are shown.
            'preview' => $open && $sensitive ? $service->preview($payrollImport) : [],
            'mapping' => $payrollImport->column_mapping ?? ($open ? $service->suggestMapping($payrollImport, $components) : null),
        ]);
    }

    public function mapping(Request $request, PayrollImport $payrollImport, PayrollImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:3'], 'employee_name' => ['nullable', 'string', 'max:3'],
            'stated_gross' => ['nullable', 'string', 'max:3'], 'stated_net' => ['nullable', 'string', 'max:3'], 'pay_date' => ['nullable', 'string', 'max:3'],
            'components' => ['nullable', 'array'], 'components.*' => ['nullable', 'string', 'max:3'],
            'required' => ['nullable', 'array'], 'required.*' => ['integer'],
        ]);
        $import = $service->applyMapping($payrollImport, $data, $request->user());

        return redirect()->route('payroll-imports.report', $import)->with('status', 'Columns mapped and every row validated. Review the report before confirming.');
    }

    /** Validation and error report, with the confirmation form. */
    public function report(Request $request, PayrollImport $payrollImport, BranchAccess $access, PayrollImportService $service, EmployeeService $employees): View|RedirectResponse
    {
        $this->guardBranch($request, $payrollImport, $access);
        if (in_array($payrollImport->status, [PayrollImportStatus::Uploaded, PayrollImportStatus::Mapped, PayrollImportStatus::Cancelled], true)) {
            return redirect()->route('payroll-imports.show', $payrollImport);
        }
        $sensitive = $employees->canViewSensitive($request->user());
        $rows = $payrollImport->rows()->select($sensitive ? ['*'] : ['id', 'payroll_import_id', 'row_number', 'employee_id', 'errors', 'warnings', 'has_formula_warning', 'is_excluded'])
            ->with('employee:id,employee_code,name')
            ->when($request->string('show')->toString() === 'errors', fn ($query) => $query->whereJsonLength('errors', '>', 0))
            ->when($request->string('show')->toString() === 'warnings', fn ($query) => $query->whereJsonLength('warnings', '>', 0))
            ->when($request->string('show')->toString() === 'excluded', fn ($query) => $query->where('is_excluded', true))
            ->paginate(100)->withQueryString();

        return view('payroll.imports.report', [
            'import' => $payrollImport->load(['branch', 'period', 'run']), 'rows' => $rows, 'sensitive' => $sensitive, 'counts' => $service->counts($payrollImport),
            'components' => PayrollComponent::query()->whereKey(array_keys($payrollImport->column_mapping['components'] ?? []))->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function export(Request $request, PayrollImport $payrollImport, BranchAccess $access, PayrollImportService $service, AuditLogger $audit): StreamedResponse
    {
        $this->guardBranch($request, $payrollImport, $access);
        $rows = $service->report($payrollImport);
        $audit->log('payroll_import.report_exported', $payrollImport, ['rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Row', 'Employee Code', 'Status', 'Errors', 'Warnings']);
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection from workbook text.
                fputcsv($handle, array_map(fn (string $value): string => preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value, $row));
            }
            fclose($handle);
        }, 'payroll-import-report-'.$payrollImport->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exclude(Request $request, PayrollImport $payrollImport, PayrollImportService $service): RedirectResponse
    {
        $data = $request->validate(['rows' => ['required', 'array', 'min:1'], 'rows.*' => ['integer'], 'action' => ['required', 'in:exclude,include']]);
        $service->setExcluded($payrollImport, array_map('intval', $data['rows']), $data['action'] === 'exclude', $request->user());

        return redirect()->route('payroll-imports.report', $payrollImport)->with('status', $data['action'] === 'exclude' ? 'Selected rows excluded and the file validated again.' : 'Selected rows returned to the import and the file validated again.');
    }

    public function confirm(Request $request, PayrollImport $payrollImport, PayrollImportService $service): RedirectResponse
    {
        $data = $request->validate(['acknowledge_formulas' => ['nullable', 'boolean']]);
        $run = $service->confirm($payrollImport, (bool) ($data['acknowledge_formulas'] ?? false), $request->user());

        return redirect()->route($request->user()->can('payroll.view') ? 'payroll-runs.show' : 'payroll-imports.report', $request->user()->can('payroll.view') ? $run : $payrollImport)
            ->with('status', 'Import confirmed. A draft payroll run was created; it still has to be validated, approved and posted.');
    }

    public function cancel(Request $request, PayrollImport $payrollImport, PayrollImportService $service): RedirectResponse
    {
        $service->cancel($payrollImport, $request->user());

        return redirect()->route('payroll-imports.index')->with('status', 'Import cancelled and its stored file removed.');
    }

    private function guardBranch(Request $request, PayrollImport $import, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $import->branch), 403);
    }
}
