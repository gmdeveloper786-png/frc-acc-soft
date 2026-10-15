<?php

namespace App\Services\Reports\Statements;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;

/**
 * Accrual Income & Expenditure statement for a period. Read-only.
 */
class IncomeExpenditureStatement
{
    public const METHOD = 'Accrual basis. Posted journals only (drafts, submitted and approved-but-unposted journals are excluded), by transaction date. Income = credits minus debits; expenses = debits minus credits. Parent accounts are rollups of their ledgers. Year-end closing journals (and their reversals) are excluded, so a closed year still shows its income and expenditure.';

    public function __construct(private LedgerActivityReader $reader, private AccountTree $tree, private StatementPeriod $periods) {}

    /**
     * @param  array<string, mixed>  $filters  Keys: branch_id, fiscal_year_id, from, to, compare.
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters): array
    {
        $scope = StatementScope::resolve($actor, $filters['branch_id'] ?? null);
        $period = $this->periods->resolve($filters);
        if ($period === null) {
            return ['scope' => $scope, 'period' => null];
        }

        $types = [AccountType::Income, AccountType::Expense];
        $movements = [];
        foreach ($this->reader->totals($scope->branchIds(), $period['from'], $period['to'], $types, excludeClosing: true) as $row) {
            $movements[$row['account_id']]['current'] = $row['net'];
        }

        $columns = ['current'];
        $comparative = null;
        $notice = $period['comparative_notice'];
        if ($period['comparative'] !== null) {
            $previous = $this->reader->totals($scope->branchIds(), $period['comparative']['from'], $period['comparative']['to'], $types, excludeClosing: true);
            if ($previous === []) {
                // A comparison with nothing posted would be a column of invented zeros.
                $notice = 'No posted income or expenditure exists for '.$period['comparative']['label'].', so no comparison is shown.';
            } else {
                $comparative = $period['comparative'];
                $columns[] = 'comparative';
                foreach ($previous as $row) {
                    $movements[$row['account_id']]['comparative'] = $row['net'];
                }
            }
        }

        $accounts = Account::query()->whereIn('account_type', [AccountType::Income->value, AccountType::Expense->value])->orderBy('sort_order')->orderBy('code')->get();
        $income = $this->tree->build($accounts, AccountType::Income, $movements, $columns);
        $expenses = $this->tree->build($accounts, AccountType::Expense, $movements, $columns);
        $surplus = [];
        foreach ($columns as $column) {
            $surplus[$column] = AccountingDecimal::string(BigDecimal::of($income['totals'][$column])->minus($expenses['totals'][$column]));
        }

        return [
            'scope' => $scope, 'period' => $period, 'columns' => $columns, 'comparative' => $comparative, 'comparative_notice' => $notice,
            'income' => $income, 'expenses' => $expenses,
            'total_income' => $income['totals'], 'total_expenses' => $expenses['totals'], 'surplus' => $surplus,
            'has_activity' => $movements !== [],
        ];
    }

    /** @param array<string, mixed> $report A report returned by `build` with a resolved period. */
    public function table(array $report): ReportTable
    {
        $columns = [['key' => 'account', 'label' => 'Account'], ['key' => 'current', 'label' => 'Current period', 'type' => 'money']];
        $meta = ['Period' => $report['period']['label'], 'Branch scope' => $report['scope']->label, 'Basis' => 'Accrual, posted journals only'];
        if ($report['comparative'] !== null) {
            $columns[] = ['key' => 'comparative', 'label' => 'Comparative period', 'type' => 'money'];
            $meta['Comparative period'] = $report['comparative']['label'];
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

        return new ReportTable('Income & Expenditure Statement', $columns, $rows, $meta, trim(self::METHOD.' '.($report['comparative_notice'] ?? '')));
    }
}
