<?php

namespace App\Services\Reports\Statements;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;

/**
 * Income & Expenditure with one column per accessible branch and a total. Read-only.
 */
class BranchIncomeExpenditureStatement
{
    public const TOTAL = 'total';

    public function __construct(private LedgerActivityReader $reader, private AccountTree $tree, private StatementPeriod $periods) {}

    /**
     * @param  array<string, mixed>  $filters  Keys: fiscal_year_id, from, to.
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters): array
    {
        $scope = StatementScope::resolve($actor, null);
        $period = $this->periods->resolve([...$filters, 'compare' => null]);
        if ($period === null) {
            return ['scope' => $scope, 'period' => null];
        }

        $zero = BigDecimal::zero()->toScale(4);
        $branchColumns = [];
        foreach ($scope->branches as $branch) {
            $branchColumns['branch_'.$branch->id] = $branch->name;
        }
        $columns = [...array_keys($branchColumns), self::TOTAL];

        $movements = [];
        $types = [AccountType::Income, AccountType::Expense];
        foreach ($this->reader->totals($scope->branchIds(), $period['from'], $period['to'], $types, LedgerActivityReader::BY_BRANCH, excludeClosing: true) as $row) {
            $movements[$row['account_id']]['branch_'.$row['dimension']] = $row['net'];
            // The total is the sum of the branch columns shown, nothing else.
            $movements[$row['account_id']][self::TOTAL] = ($movements[$row['account_id']][self::TOTAL] ?? $zero)->plus($row['net']);
        }

        $accounts = Account::query()->whereIn('account_type', [AccountType::Income->value, AccountType::Expense->value])->orderBy('sort_order')->orderBy('code')->get();
        $income = $this->tree->build($accounts, AccountType::Income, $movements, $columns);
        $expenses = $this->tree->build($accounts, AccountType::Expense, $movements, $columns);
        $surplus = [];
        foreach ($columns as $column) {
            $surplus[$column] = AccountingDecimal::string(BigDecimal::of($income['totals'][$column])->minus($expenses['totals'][$column]));
        }

        return [
            'scope' => $scope, 'period' => $period, 'columns' => $columns, 'branch_columns' => $branchColumns,
            'total_label' => $scope->consolidated ? 'Total FRC (all branches)' : 'Total of shown branches',
            'income' => $income, 'expenses' => $expenses,
            'total_income' => $income['totals'], 'total_expenses' => $expenses['totals'], 'surplus' => $surplus,
            'has_activity' => $movements !== [],
        ];
    }

    /** @param array<string, mixed> $report */
    public function note(array $report): string
    {
        return IncomeExpenditureStatement::METHOD.' '.($report['scope']->consolidated
            ? 'The consolidated FRC figure is the sum of all branch columns.'
            : 'Only the branches you are authorized for are shown; the total is the total of the shown branches, not of all FRC.');
    }

    /** @param array<string, mixed> $report */
    public function table(array $report): ReportTable
    {
        $columns = [['key' => 'account', 'label' => 'Account']];
        foreach ($report['branch_columns'] as $key => $name) {
            $columns[] = ['key' => $key, 'label' => $name, 'type' => 'money'];
        }
        $columns[] = ['key' => self::TOTAL, 'label' => $report['total_label'], 'type' => 'money'];

        $rows = [
            ['account' => 'Income', '_style' => 'heading'],
            ...AccountTree::exportRows($report['income']),
            ['account' => 'Total Income', ...$report['total_income'], '_style' => 'total'],
            ['account' => 'Expenses', '_style' => 'heading'],
            ...AccountTree::exportRows($report['expenses']),
            ['account' => 'Total Expenses', ...$report['total_expenses'], '_style' => 'total'],
            ['account' => 'Surplus/(Deficit)', ...$report['surplus'], '_style' => 'total'],
        ];

        return new ReportTable('Branch-wise Income & Expenditure', $columns, $rows, [
            'Period' => $report['period']['label'], 'Branch scope' => $report['scope']->label, 'Basis' => 'Accrual, posted journals only',
        ], $this->note($report));
    }
}
