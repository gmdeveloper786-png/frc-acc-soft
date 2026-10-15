<?php

namespace App\Services\Funds;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Fund;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use App\Services\Reports\Statements\AccountTree;
use App\Services\Reports\Statements\LedgerActivityReader;
use App\Services\Reports\Statements\StatementPeriod;
use Brick\Math\BigDecimal;

/**
 * Fund-wise Income & Expenditure: one column per fund with posted activity,
 * a "No fund" column for lines without a fund, and a total. Every posted line
 * is counted once, in exactly one column, so the total equals the plain
 * Income & Expenditure statement. Read-only.
 */
class FundIncomeExpenditureReport
{
    public const NO_FUND = 'none';

    public const TOTAL = 'total';

    public const METHOD = 'Accrual basis. Posted journals only, by transaction date, attributed by the fund recorded on each journal line. Lines without a fund are shown under "No fund" and are never distributed. Income = credits minus debits; expenses = debits minus credits. Year-end closing journals (and their reversals) are excluded.';

    public function __construct(private LedgerActivityReader $reader, private AccountTree $tree, private StatementPeriod $periods) {}

    /**
     * @param  array<string, mixed>  $filters  Keys: branch_id, fiscal_year_id, from, to, fund_id.
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters): array
    {
        $scope = FundReportScope::resolve($actor, $filters['branch_id'] ?? null);
        $period = $this->periods->resolve([...$filters, 'compare' => null]);
        if ($period === null) {
            return ['scope' => $scope, 'period' => null];
        }

        $selected = FundFilter::funds($filters['fund_id'] ?? null);
        $allowed = $selected?->modelKeys();
        $zero = BigDecimal::zero()->toScale(4);
        $movements = [];
        $active = [];
        $types = [AccountType::Income, AccountType::Expense];
        foreach ($this->reader->totals($scope->branchIds(), $period['from'], $period['to'], $types, LedgerActivityReader::BY_FUND, excludeClosing: true) as $row) {
            $fundId = $row['dimension'];
            if ($allowed !== null && ! in_array($fundId, $allowed, true)) {
                continue;
            }
            $key = $fundId === null ? self::NO_FUND : 'fund_'.$fundId;
            $movements[$row['account_id']][$key] = $row['net'];
            // The total is the sum of the columns shown, nothing else.
            $movements[$row['account_id']][self::TOTAL] = ($movements[$row['account_id']][self::TOTAL] ?? $zero)->plus($row['net']);
            if ($fundId !== null) {
                $active[$fundId] = true;
            }
        }

        $funds = $selected ?? Fund::query()->whereKey(array_keys($active))->orderBy('code')->get();
        $fundColumns = [];
        foreach ($funds as $fund) {
            $fundColumns['fund_'.$fund->id] = $fund->code;
        }
        $columns = [...array_keys($fundColumns), ...($selected === null ? [self::NO_FUND] : []), self::TOTAL];
        $labels = $fundColumns + ($selected === null ? [self::NO_FUND => 'No fund'] : []) + [self::TOTAL => $selected === null ? 'Total (all lines)' : 'Total of shown funds'];

        $accounts = Account::query()->whereIn('account_type', [AccountType::Income->value, AccountType::Expense->value])->orderBy('sort_order')->orderBy('code')->get();
        $income = $this->tree->build($accounts, AccountType::Income, $movements, $columns);
        $expenses = $this->tree->build($accounts, AccountType::Expense, $movements, $columns);
        $surplus = [];
        foreach ($columns as $column) {
            $surplus[$column] = AccountingDecimal::string(BigDecimal::of($income['totals'][$column])->minus($expenses['totals'][$column]));
        }

        return [
            'scope' => $scope, 'period' => $period, 'columns' => $columns, 'labels' => $labels, 'funds' => $funds, 'selected' => $selected,
            'income' => $income, 'expenses' => $expenses,
            'total_income' => $income['totals'], 'total_expenses' => $expenses['totals'], 'surplus' => $surplus,
            'has_activity' => $movements !== [],
        ];
    }

    /** @param array<string, mixed> $report */
    public function table(array $report): ReportTable
    {
        $columns = [['key' => 'account', 'label' => 'Account']];
        foreach ($report['columns'] as $key) {
            $columns[] = ['key' => $key, 'label' => $report['labels'][$key], 'type' => 'money'];
        }

        $rows = [
            ['account' => 'Income', '_style' => 'heading'],
            ...AccountTree::exportRows($report['income']),
            ['account' => 'Total Income', ...$report['total_income'], '_style' => 'total'],
            ['account' => 'Expenses', '_style' => 'heading'],
            ...AccountTree::exportRows($report['expenses']),
            ['account' => 'Total Expenses', ...$report['total_expenses'], '_style' => 'total'],
            ['account' => 'Surplus/(Deficit)', ...$report['surplus'], '_style' => 'total'],
        ];

        return new ReportTable('Fund-wise Income & Expenditure', $columns, $rows, [
            'Period' => $report['period']['label'], 'Branch scope' => $report['scope']->label,
            'Funds' => $report['selected'] === null ? 'All funds and lines without a fund' : $report['funds']->map(fn (Fund $fund): string => $fund->label())->implode(', '),
            'Basis' => 'Accrual, posted journals only',
        ], self::METHOD);
    }
}
