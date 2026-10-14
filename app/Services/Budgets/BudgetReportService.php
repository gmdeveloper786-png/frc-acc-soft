<?php

namespace App\Services\Budgets;

use App\Enums\AccountType;
use App\Enums\BudgetAlertLevel;
use App\Enums\BudgetStatus;
use App\Enums\BudgetUtilisationStatus;
use App\Enums\JournalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\FiscalYear;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AuditLogger;
use App\Services\BranchAccess;
use App\Services\Reports\ReportTable;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Budget vs Actual reporting. Actual is read from the General Ledger only;
 * purchase order commitments are shown beside it and never added to it.
 * Reports warn and record overruns but never stop a transaction.
 */
class BudgetReportService
{
    /** The statuses the General Ledger and Trial Balance count as ledger activity. */
    public const LEDGER_STATUSES = [JournalStatus::Posted, JournalStatus::Reversed];

    private const EXPORT_NOTE = 'Actual is posted General Ledger activity only. Variance = actual - budget; remaining = budget - actual. Purchase order commitments are shown separately and are never added to Actual.';

    public function __construct(
        private BranchAccess $branchAccess,
        private AppSettings $settings,
        private AuditLogger $audit,
    ) {}

    public function warningPercentage(): int
    {
        return $this->settings->integer('budget.warning_percentage', (int) config('accounting.budget_warning_percentage'));
    }

    /** The single approved budget for a fiscal year, branch and service scope, if one exists. */
    public function approvedBudget(int $fiscalYearId, int $branchId, ?int $serviceId): ?Budget
    {
        return Budget::query()->with(['fiscalYear', 'branch', 'service'])
            ->where('fiscal_year_id', $fiscalYearId)->where('branch_id', $branchId)
            ->where('service_scope', Budget::scopeFor($serviceId))->where('status', BudgetStatus::Approved->value)->first();
    }

    /**
     * The versions that may be reported for a fiscal year, branch and service
     * scope: the approved one and the versions it superseded, newest first.
     * Draft, submitted and rejected versions are never reported.
     *
     * @return Collection<int, Budget>
     */
    public function reportableVersions(int $fiscalYearId, int $branchId, ?int $serviceId): Collection
    {
        return Budget::query()->with(['fiscalYear', 'branch', 'service'])
            ->where('fiscal_year_id', $fiscalYearId)->where('branch_id', $branchId)->where('service_scope', Budget::scopeFor($serviceId))
            ->whereIn('status', [BudgetStatus::Approved->value, BudgetStatus::Superseded->value])
            ->orderByDesc('version')->orderByDesc('id')->get();
    }

    /**
     * Budget vs Actual for one budget over a range of its fiscal year's periods.
     * Actual is the same posted ledger activity whichever version is compared.
     *
     * @return array{budget: Budget, periods: Collection<int, AccountingPeriod>, from: AccountingPeriod, to: AccountingPeriod, warning_percentage: int, rows: list<array<string, mixed>>, hierarchy: list<array<string, mixed>>, totals: array<string, array<string, mixed>>, alerts_recorded: int}
     */
    public function versusActual(Budget $budget, User $actor, ?int $fromPeriodId = null, ?int $toPeriodId = null, bool $recordAlerts = true): array
    {
        abort_unless($actor->is_active && $actor->can('budget_reports.view'), 403);
        $budget->loadMissing(['branch', 'fiscalYear', 'service']);
        abort_unless($this->branchAccess->canAccess($actor, $budget->branch), 403);

        $yearPeriods = $budget->fiscalYear->periods()->get();
        if ($yearPeriods->isEmpty()) {
            throw ValidationException::withMessages(['fiscal_year_id' => 'The fiscal year of this budget has no accounting periods.']);
        }
        $from = $fromPeriodId ? $yearPeriods->firstWhere('id', $fromPeriodId) : $yearPeriods->first();
        $to = $toPeriodId ? $yearPeriods->firstWhere('id', $toPeriodId) : $yearPeriods->last();
        if (! $from || ! $to || $from->period_number > $to->period_number) {
            throw ValidationException::withMessages(['from_period_id' => 'Select a period range inside the fiscal year of the budget.']);
        }
        $periods = $yearPeriods->filter(fn (AccountingPeriod $period): bool => $period->period_number >= $from->period_number && $period->period_number <= $to->period_number)->values();
        $periodIds = $periods->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $lines = $budget->lines()->get();
        $accountIds = $lines->pluck('account_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $accounts = Account::query()->orderBy('sort_order')->orderBy('code')->get(['id', 'parent_id', 'code', 'name', 'level', 'account_type', 'sort_order'])->keyBy('id');
        $budgeted = [];
        foreach ($lines as $line) {
            $budgeted[(int) $line->account_id][(int) $line->accounting_period_id] = AccountingDecimal::of($line->amount);
        }
        $actuals = $this->actuals($budget, $accountIds, $periodIds);
        $commitments = $this->commitments($budget, $accountIds);
        $warning = $this->warningPercentage();
        $zero = BigDecimal::zero()->toScale(4);

        $rows = [];
        foreach ($accounts->only($accountIds) as $account) {
            $isExpense = $account->account_type === AccountType::Expense;
            [$budgetSum, $actualSum, $periodRows] = [$zero, $zero, []];
            foreach ($periods as $period) {
                $allocation = $budgeted[$account->id][$period->id] ?? null;
                $movement = $actuals[$account->id][$period->id] ?? null;
                if ($allocation === null && $movement === null) {
                    continue;
                }
                $actual = $movement ? ($isExpense ? $movement['debit']->minus($movement['credit']) : $movement['credit']->minus($movement['debit'])) : $zero;
                $budgetSum = $budgetSum->plus($allocation ?? $zero);
                $actualSum = $actualSum->plus($actual);
                $periodRows[] = ['period' => $period, 'has_budget_line' => $allocation !== null, ...$this->figures($allocation ?? $zero, $actual, $isExpense, $warning)];
            }
            $rows[] = [
                'account' => $account, 'is_ledger' => true, 'depth' => 0, 'periods' => $periodRows,
                ...$this->figures($budgetSum, $actualSum, $isExpense, $warning, $isExpense ? ($commitments[$account->id] ?? $zero) : null),
            ];
        }

        $report = [
            'budget' => $budget, 'periods' => $periods, 'from' => $from, 'to' => $to, 'warning_percentage' => $warning, 'is_year_to_date' => $from->id === $yearPeriods->first()->id,
            'rows' => $rows, 'hierarchy' => $this->hierarchy($rows, $accounts, $warning), 'totals' => $this->totals($rows, $warning), 'alerts_recorded' => 0,
        ];
        if ($recordAlerts && $budget->status === BudgetStatus::Approved) {
            $report['alerts_recorded'] = $this->recordAlerts($report, $actor);
        }

        return $report;
    }

    /**
     * One row per branch for whole-branch budgets, or one row per approved
     * service budget. A branch without an approved budget is reported as such,
     * never as zeros.
     *
     * @param  Collection<int, Branch>  $branches
     * @return list<array{branch: Branch, budget: ?Budget, report: ?array<string, mixed>}>
     */
    public function summary(FiscalYear $year, User $actor, Collection $branches, bool $serviceBudgets, ?int $fromPeriodId = null, ?int $toPeriodId = null, bool $recordAlerts = true): array
    {
        abort_unless($actor->is_active && $actor->can('budget_reports.view'), 403);
        $branches = $branches->filter(fn (Branch $branch): bool => $this->branchAccess->canAccess($actor, $branch))->values();
        $budgets = Budget::query()->with(['fiscalYear', 'branch', 'service'])
            ->where('fiscal_year_id', $year->id)->whereIn('branch_id', $branches->pluck('id'))->where('status', BudgetStatus::Approved->value)
            ->when($serviceBudgets, fn ($query) => $query->whereNotNull('service_id'), fn ($query) => $query->whereNull('service_id'))
            ->orderBy('branch_id')->orderBy('name')->get();

        $rows = [];
        foreach ($branches as $branch) {
            $branchBudgets = $budgets->where('branch_id', $branch->id);
            if ($branchBudgets->isEmpty()) {
                $rows[] = ['branch' => $branch, 'budget' => null, 'report' => null];

                continue;
            }
            foreach ($branchBudgets as $budget) {
                $rows[] = ['branch' => $branch, 'budget' => $budget, 'report' => $this->versusActual($budget, $actor, $fromPeriodId, $toPeriodId, $recordAlerts)];
            }
        }

        return $rows;
    }

    /**
     * Budget vs Actual as an export table, built from the report the screen
     * shows. Group rows are subtotals of their ledgers and the totals count
     * every ledger once.
     *
     * @param  array<string, mixed>  $report
     */
    public function versusActualTable(array $report, User $actor, bool $hierarchy): ReportTable
    {
        $this->authorizeExport($actor);
        $budget = $report['budget'];
        $rows = [];
        foreach ($hierarchy ? $report['hierarchy'] : $report['rows'] as $row) {
            $rows[] = [
                'account' => $row['account']->code.' '.$row['account']->name, ...$this->exportFigures($row),
                ...($hierarchy ? ['_indent' => $row['depth']] : []), ...($row['is_ledger'] ? [] : ['_style' => 'subtotal']),
            ];
        }
        foreach ([AccountType::Expense->value => 'Total expenses (each ledger once)', AccountType::Income->value => 'Total income (each ledger once)'] as $type => $label) {
            if ($report['totals'][$type]['has_lines']) {
                $rows[] = ['account' => $label, ...$this->exportFigures($report['totals'][$type]), '_style' => 'total'];
            }
        }

        return new ReportTable('Budget vs Actual', [['key' => 'account', 'label' => 'Account'], ...$this->exportColumns()], $rows, [
            'Fiscal year' => $budget->fiscalYear->name, 'Branch' => $budget->branch->name, 'Service' => $budget->service?->name ?? 'Whole branch',
            'Budget' => $budget->name.' v'.$budget->version.' ('.$this->versionLabel($budget).')',
            'Periods' => $report['from']->name.' to '.$report['to']->name, 'Layout' => $hierarchy ? 'Account hierarchy' : 'Ledgers only',
            'Warning threshold' => $report['warning_percentage'].'%',
        ], self::EXPORT_NOTE);
    }

    /**
     * Branch or service budget summary as an export table.
     *
     * @param  list<array{branch: Branch, budget: ?Budget, report: ?array<string, mixed>}>  $summary
     * @param  array<string, string>  $meta
     */
    public function summaryTable(array $summary, User $actor, bool $serviceBudgets, array $meta): ReportTable
    {
        $this->authorizeExport($actor);
        $rows = [];
        foreach ($summary as $entry) {
            if ($entry['budget'] === null) {
                $rows[] = ['branch' => $entry['branch']->name, 'service' => '', 'budget_name' => 'No approved '.($serviceBudgets ? 'service' : 'whole-branch').' budget', 'type' => ''];

                continue;
            }
            foreach ([AccountType::Expense->value => 'Expenses', AccountType::Income->value => 'Income'] as $type => $label) {
                if ($entry['report']['totals'][$type]['has_lines']) {
                    $rows[] = [
                        'branch' => $entry['branch']->name, 'service' => $entry['budget']->service?->name ?? 'Whole branch',
                        'budget_name' => $entry['budget']->name.' v'.$entry['budget']->version, 'type' => $label, ...$this->exportFigures($entry['report']['totals'][$type]),
                    ];
                }
            }
        }
        $columns = [['key' => 'branch', 'label' => 'Branch'], ['key' => 'service', 'label' => 'Service'], ['key' => 'budget_name', 'label' => 'Budget'], ['key' => 'type', 'label' => 'Type']];
        if (! $serviceBudgets) {
            unset($columns[1]);
        }

        return new ReportTable($serviceBudgets ? 'Service Budget Summary' : 'Branch Budget Summary', [...array_values($columns), ...$this->exportColumns()], $rows,
            [...$meta, 'Warning threshold' => $this->warningPercentage().'%'], self::EXPORT_NOTE);
    }

    public function versionLabel(Budget $budget): string
    {
        return $budget->status === BudgetStatus::Superseded ? 'Superseded version' : $budget->status->label().' version';
    }

    private function authorizeExport(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can('budget_reports.view') && $actor->can('budget_reports.export'), 403);
    }

    /** @return list<array{key: string, label: string, type?: string}> */
    private function exportColumns(): array
    {
        return [
            ['key' => 'budget', 'label' => 'Approved budget', 'type' => 'money'], ['key' => 'actual', 'label' => 'Actual (posted)', 'type' => 'money'],
            ['key' => 'variance', 'label' => 'Variance', 'type' => 'money'], ['key' => 'utilisation', 'label' => 'Utilisation %', 'type' => 'percent'],
            ['key' => 'remaining', 'label' => 'Remaining', 'type' => 'money'], ['key' => 'commitments', 'label' => 'PO commitments (not in Actual)', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, ?string>
     */
    private function exportFigures(array $figures): array
    {
        return [
            'budget' => $figures['budget'], 'actual' => $figures['actual'], 'variance' => $figures['variance'], 'utilisation' => $figures['utilisation'],
            'remaining' => $figures['remaining'], 'commitments' => $figures['commitments'] ?? null, 'status' => $figures['status']->label(),
        ];
    }

    /**
     * Ledger activity per account and period. Only posted journals count, and
     * a reversed journal keeps counting so that it nets to zero with its
     * posted reversal, exactly as in the General Ledger and Trial Balance.
     *
     * @param  list<int>  $accountIds
     * @param  list<int>  $periodIds
     * @return array<int, array<int, array{debit: BigDecimal, credit: BigDecimal}>>
     */
    private function actuals(Budget $budget, array $accountIds, array $periodIds): array
    {
        if ($accountIds === [] || $periodIds === []) {
            return [];
        }
        $sums = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', array_map(fn (JournalStatus $status): string => $status->value, self::LEDGER_STATUSES))
            ->where('journal_entries.branch_id', $budget->branch_id)
            ->where('journal_entries.fiscal_year_id', $budget->fiscal_year_id)
            ->whereIn('journal_entries.accounting_period_id', $periodIds)
            ->whereIn('journal_lines.account_id', $accountIds)
            ->when($budget->service_id, fn ($query) => $query->where('journal_lines.service_id', $budget->service_id))
            ->groupBy('journal_lines.account_id', 'journal_entries.accounting_period_id')
            ->selectRaw('journal_lines.account_id as account_id, journal_entries.accounting_period_id as period_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get();

        $actuals = [];
        foreach ($sums as $sum) {
            $actuals[(int) $sum->account_id][(int) $sum->period_id] = ['debit' => $this->sum($sum->debit), 'credit' => $this->sum($sum->credit)];
        }

        return $actuals;
    }

    /**
     * Unbilled value of approved or issued purchase orders of the branch,
     * dated inside the fiscal year, per budgeted ledger.
     *
     * @param  list<int>  $accountIds
     * @return array<int, BigDecimal>
     */
    private function commitments(Budget $budget, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $year = $budget->fiscalYear;
        $lines = PurchaseOrderLine::query()
            ->whereIn('account_id', $accountIds)
            ->when($budget->service_id, fn ($query) => $query->where('service_id', $budget->service_id))
            ->whereHas('purchaseOrder', fn ($query) => $query->where('branch_id', $budget->branch_id)
                ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Issued->value])
                ->whereDate('po_date', '>=', $year->start_date->toDateString())->whereDate('po_date', '<=', $year->end_date->toDateString()))
            ->get();

        $commitments = [];
        foreach ($lines as $line) {
            $quantity = AccountingDecimal::of($line->quantity);
            $remaining = AccountingDecimal::of($line->remainingQuantity());
            if (! $quantity->isPositive() || ! $remaining->isPositive()) {
                continue;
            }
            $remaining = $remaining->isGreaterThan($quantity) ? $quantity : $remaining;
            $value = AccountingDecimal::of($line->line_total)->multipliedBy($remaining)->dividedBy($quantity, 4, RoundingMode::HalfUp);
            $commitments[(int) $line->account_id] = ($commitments[(int) $line->account_id] ?? BigDecimal::zero()->toScale(4))->plus($value);
        }

        return $commitments;
    }

    /**
     * @return array{budget: string, actual: string, remaining: string, variance: string, utilisation: ?string, status: BudgetUtilisationStatus, commitments: ?string}
     */
    private function figures(BigDecimal $budget, BigDecimal $actual, bool $isExpense, int $warning, ?BigDecimal $commitments = null): array
    {
        $utilisation = $budget->isPositive() ? $actual->multipliedBy(100)->dividedBy($budget, 2, RoundingMode::HalfUp) : null;
        $status = match (true) {
            ! $isExpense => BudgetUtilisationStatus::IncomeTarget,
            ! $budget->isPositive() => $actual->isPositive() ? BudgetUtilisationStatus::Unbudgeted : BudgetUtilisationStatus::Within,
            $actual->isGreaterThan($budget) => BudgetUtilisationStatus::Over,
            $actual->multipliedBy(100)->isGreaterThanOrEqualTo($budget->multipliedBy($warning)) => BudgetUtilisationStatus::Warning,
            default => BudgetUtilisationStatus::Within,
        };

        return [
            'budget' => AccountingDecimal::string($budget), 'actual' => AccountingDecimal::string($actual),
            'remaining' => AccountingDecimal::string($budget->minus($actual)), 'variance' => AccountingDecimal::string($actual->minus($budget)),
            'utilisation' => $utilisation === null ? null : (string) $utilisation, 'status' => $status,
            'commitments' => $commitments === null ? null : AccountingDecimal::string($commitments),
        ];
    }

    /**
     * Class, group and sub-group summaries. Every budgeted ledger is added to
     * each of its ancestors exactly once; parents carry no amount of their own.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Account>  $accounts
     * @return list<array<string, mixed>>
     */
    private function hierarchy(array $rows, Collection $accounts, int $warning): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        $ledgerRows = collect($rows)->keyBy(fn (array $row): int => $row['account']->id);
        $sums = [];
        foreach ($rows as $row) {
            $visited = [$row['account']->id => true];
            $parent = $row['account']->parent_id ? $accounts->get($row['account']->parent_id) : null;
            while ($parent && ! isset($visited[$parent->id])) {
                $visited[$parent->id] = true;
                $sums[$parent->id] ??= ['budget' => $zero, 'actual' => $zero, 'commitments' => $zero, 'ledgers' => 0];
                $sums[$parent->id]['budget'] = $sums[$parent->id]['budget']->plus($row['budget']);
                $sums[$parent->id]['actual'] = $sums[$parent->id]['actual']->plus($row['actual']);
                $sums[$parent->id]['commitments'] = $sums[$parent->id]['commitments']->plus($row['commitments'] ?? '0');
                $sums[$parent->id]['ledgers']++;
                $parent = $parent->parent_id ? $accounts->get($parent->parent_id) : null;
            }
        }

        $included = $accounts->filter(fn (Account $account): bool => isset($sums[$account->id]) || $ledgerRows->has($account->id));
        $children = $included->groupBy(fn (Account $account): int => $account->parent_id && $included->has($account->parent_id) ? (int) $account->parent_id : 0);
        $tree = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$tree, $children, $ledgerRows, $sums, $warning): void {
            foreach ($children->get($parentId, collect()) as $account) {
                if ($ledgerRows->has($account->id)) {
                    $tree[] = [...$ledgerRows->get($account->id), 'depth' => $depth];
                } else {
                    $isExpense = $account->account_type === AccountType::Expense;
                    $tree[] = [
                        'account' => $account, 'is_ledger' => false, 'depth' => $depth, 'periods' => [], 'ledger_count' => $sums[$account->id]['ledgers'],
                        ...$this->figures($sums[$account->id]['budget'], $sums[$account->id]['actual'], $isExpense, $warning, $isExpense ? $sums[$account->id]['commitments'] : null),
                    ];
                }
                $walk($account->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $tree;
    }

    /**
     * Expense and income totals, each counting every budgeted ledger once.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function totals(array $rows, int $warning): array
    {
        $totals = [];
        foreach ([AccountType::Expense, AccountType::Income] as $type) {
            $typeRows = array_filter($rows, fn (array $row): bool => $row['account']->account_type === $type);
            $sum = fn (string $key): BigDecimal => array_reduce($typeRows, fn (BigDecimal $carry, array $row): BigDecimal => $carry->plus($row[$key] ?? '0'), BigDecimal::zero()->toScale(4));
            $isExpense = $type === AccountType::Expense;
            $totals[$type->value] = ['has_lines' => $typeRows !== [], ...$this->figures($sum('budget'), $sum('actual'), $isExpense, $warning, $isExpense ? $sum('commitments') : null)];
        }

        return $totals;
    }

    /**
     * Record each newly detected warning or overrun once. Period lines are
     * recorded per period; a range that starts at the first period of the year
     * also records a year-to-date alert per account.
     *
     * @param  array<string, mixed>  $report
     */
    private function recordAlerts(array $report, User $actor): int
    {
        $recorded = 0;
        $isYearToDate = $report['periods']->count() > 1 && $report['is_year_to_date'];
        foreach ($report['rows'] as $row) {
            foreach ($row['periods'] as $periodRow) {
                $recorded += $this->recordAlert($report, $row['account'], $periodRow, $periodRow['period'], 'period:'.$periodRow['period']->id, $actor);
            }
            if ($isYearToDate) {
                $recorded += $this->recordAlert($report, $row['account'], $row, null, 'ytd:'.$report['to']->id, $actor);
            }
        }

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $figures
     */
    private function recordAlert(array $report, Account $account, array $figures, ?AccountingPeriod $period, string $scope, User $actor): int
    {
        $level = $figures['status']->alertLevel();
        if (! $level instanceof BudgetAlertLevel) {
            return 0;
        }
        $alert = BudgetAlert::query()->firstOrCreate(
            ['budget_id' => $report['budget']->id, 'account_id' => $account->id, 'period_scope' => $scope, 'level' => $level->value],
            [
                'accounting_period_id' => $period?->id, 'utilisation_percentage' => $figures['utilisation'], 'actual_amount' => $figures['actual'],
                'budget_amount' => $figures['budget'], 'warning_percentage' => $report['warning_percentage'], 'detected_by' => $actor->id, 'detected_at' => now(),
            ],
        );
        if (! $alert->wasRecentlyCreated) {
            return 0;
        }
        $this->audit->log('budgets.alert_recorded', $alert, [
            'budget_id' => $report['budget']->id, 'branch_id' => $report['budget']->branch_id, 'account_id' => $account->id, 'account_code' => $account->code,
            'period_scope' => $scope, 'level' => $level->value, 'utilisation_percentage' => $figures['utilisation'],
            'actual_amount' => $figures['actual'], 'budget_amount' => $figures['budget'], 'warning_percentage' => $report['warning_percentage'],
        ]);

        return 1;
    }

    /** A database SUM of DECIMAL(19,4) columns as an exact four-decimal value. */
    private function sum(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }
}
