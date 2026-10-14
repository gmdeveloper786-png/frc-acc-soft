<?php

namespace App\Http\Controllers\Reports\Statements;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatementFilterRequest;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Services\BranchAccess;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\Statements\BalanceSheetStatement;
use App\Services\Reports\Statements\BranchIncomeExpenditureStatement;
use App\Services\Reports\Statements\IncomeExpenditureStatement;
use App\Services\Reports\Statements\ServiceAnalysisStatement;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accrual financial statements. Every action is read-only.
 */
class StatementController extends Controller
{
    private const REPORTS = [
        'income-expenditure' => IncomeExpenditureStatement::class,
        'balance-sheet' => BalanceSheetStatement::class,
        'branch-income-expenditure' => BranchIncomeExpenditureStatement::class,
        'service-analysis' => ServiceAnalysisStatement::class,
    ];

    public function __construct(private BranchAccess $access) {}

    public function incomeExpenditure(StatementFilterRequest $request, IncomeExpenditureStatement $statement): View
    {
        return $this->screen($request, 'income-expenditure', $statement);
    }

    public function balanceSheet(StatementFilterRequest $request, BalanceSheetStatement $statement): View
    {
        return $this->screen($request, 'balance-sheet', $statement);
    }

    public function branchIncomeExpenditure(StatementFilterRequest $request, BranchIncomeExpenditureStatement $statement): View
    {
        return $this->screen($request, 'branch-income-expenditure', $statement);
    }

    public function serviceAnalysis(StatementFilterRequest $request, ServiceAnalysisStatement $statement): View
    {
        return $this->screen($request, 'service-analysis', $statement);
    }

    public function export(StatementFilterRequest $request, string $report, string $format, ReportExporter $exporter): StreamedResponse
    {
        abort_unless(isset(self::REPORTS[$report]) && in_array($format, ReportExporter::FORMATS, true), 404);
        abort_unless($request->user()->can('financial_statements.export'), 403);
        // With no Chart of Accounts there is nothing truthful to export.
        abort_unless(Account::query()->exists(), 404);

        $statement = app(self::REPORTS[$report]);
        $data = $statement->build($request->user(), $request->validated());
        if ($report !== 'balance-sheet' && $data['period'] === null) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'Choose a fiscal year or a date range before exporting.']);
        }

        return $exporter->download($statement->table($data), $format, $report);
    }

    private function screen(StatementFilterRequest $request, string $key, IncomeExpenditureStatement|BalanceSheetStatement|BranchIncomeExpenditureStatement|ServiceAnalysisStatement $statement): View
    {
        $setupRequired = ! Account::query()->exists();

        return view('reports.statements.'.$key, [
            'reportKey' => $key,
            'setupRequired' => $setupRequired,
            'report' => $setupRequired ? null : $statement->build($request->user(), $request->validated()),
            'branches' => $this->access->scope($request->user())->orderBy('name')->get(),
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'canViewAll' => $request->user()->can('branches.view_all'),
        ]);
    }
}
