<?php

namespace App\Http\Controllers\Funds;

use App\Http\Controllers\Controller;
use App\Http\Requests\Funds\FundReportFilterRequest;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Services\BranchAccess;
use App\Services\Funds\FundBalanceSummary;
use App\Services\Funds\FundIncomeExpenditureReport;
use App\Services\Funds\GrantUtilisationReport;
use App\Services\Reports\ReportExporter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fund-wise statements and grant reports. Every action is read-only.
 */
class FundReportController extends Controller
{
    private const REPORTS = [
        'income-expenditure' => FundIncomeExpenditureReport::class,
        'balance-summary' => FundBalanceSummary::class,
        'grant-utilisation' => GrantUtilisationReport::class,
    ];

    public function __construct(private BranchAccess $access) {}

    public function incomeExpenditure(FundReportFilterRequest $request, FundIncomeExpenditureReport $report): View
    {
        return $this->screen($request, 'income-expenditure', fn (): array => $report->build($request->user(), $request->validated()));
    }

    public function balanceSummary(FundReportFilterRequest $request, FundBalanceSummary $report): View
    {
        return $this->screen($request, 'balance-summary', fn (): array => $report->build($request->user(), $request->validated()));
    }

    public function grantUtilisation(FundReportFilterRequest $request, GrantUtilisationReport $report): View
    {
        return $this->screen($request, 'grant-utilisation', fn (): array => $report->build($request->user(), $request->validated()));
    }

    public function donorReport(FundReportFilterRequest $request, Fund $fund, GrantUtilisationReport $report): View
    {
        return $this->screen($request, 'donor-report', fn (): array => $report->donorReport($request->user(), $fund, $request->validated()));
    }

    public function export(FundReportFilterRequest $request, string $report, string $format, ReportExporter $exporter): StreamedResponse
    {
        abort_unless(isset(self::REPORTS[$report]) && in_array($format, ReportExporter::FORMATS, true), 404);
        abort_unless($request->user()->can('financial_statements.export'), 403);
        abort_unless(Account::query()->exists(), 404);

        $service = app(self::REPORTS[$report]);
        $data = $service->build($request->user(), $request->validated());
        if (array_key_exists('period', $data) && $data['period'] === null) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'Choose a fiscal year or a date range before exporting.']);
        }

        return $exporter->download($service->table($data), $format, 'fund-'.$report);
    }

    public function exportDonorReport(FundReportFilterRequest $request, Fund $fund, string $format, GrantUtilisationReport $report, ReportExporter $exporter): StreamedResponse
    {
        abort_unless(in_array($format, ReportExporter::FORMATS, true), 404);
        abort_unless($request->user()->can('financial_statements.export'), 403);
        abort_unless(Account::query()->exists(), 404);

        return $exporter->download($report->donorTable($report->donorReport($request->user(), $fund, $request->validated())), $format, 'donor-report-'.$fund->code);
    }

    /** @param \Closure(): array<string, mixed> $build */
    private function screen(FundReportFilterRequest $request, string $key, \Closure $build): View
    {
        $setupRequired = ! Account::query()->exists();

        return view('funds.reports.'.$key, [
            'reportKey' => $key,
            'setupRequired' => $setupRequired,
            'report' => $setupRequired ? null : $build(),
            'branches' => $this->access->scope($request->user())->orderBy('name')->get(),
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'funds' => Fund::query()->orderBy('code')->get(),
            'canViewAll' => $request->user()->can('branches.view_all'),
        ]);
    }
}
