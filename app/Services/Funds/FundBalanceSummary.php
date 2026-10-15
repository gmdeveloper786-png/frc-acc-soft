<?php

namespace App\Services\Funds;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Fund;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use App\Services\Reports\Statements\LedgerActivityReader;
use App\Services\Reports\Statements\StatementPeriod;
use Brick\Math\BigDecimal;

/**
 * Income minus expenditure per fund for a period and cumulatively from the
 * first posting up to the end of the period. Read-only.
 */
class FundBalanceSummary
{
    public const METHOD = 'Posted journals only, by transaction date, attributed by the fund recorded on each journal line; lines without a fund are shown as "No fund". The balance is income minus expenditure. Cumulative figures run from the first posting to the end of the period. Opening balances carry no fund and are not included. Year-end closing journals (and their reversals) are excluded, so closing a year does not change a fund balance.';

    public function __construct(private LedgerActivityReader $reader, private StatementPeriod $periods) {}

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
        $types = Account::query()->whereIn('account_type', [AccountType::Income->value, AccountType::Expense->value])->pluck('account_type', 'id')
            ->map(fn (mixed $type): AccountType => $type instanceof AccountType ? $type : AccountType::from((string) $type));
        $periodTotals = $this->byFund($scope->branchIds(), $period['from'], $period['to'], $types->all());
        $cumulativeTotals = $this->byFund($scope->branchIds(), null, $period['to'], $types->all());

        $funds = $selected ?? Fund::query()->where(fn ($query) => $query->where('is_active', true)->orWhereKey(array_filter(array_keys($cumulativeTotals))))->orderBy('code')->get();
        $rows = [];
        $keys = [];
        foreach ($funds as $fund) {
            $keys[] = [$fund->id, $fund];
        }
        if ($selected === null) {
            $keys[] = [0, null];
        }

        $zero = BigDecimal::zero()->toScale(4);
        $grand = array_fill_keys(['period_income', 'period_expenditure', 'period_balance', 'cumulative_income', 'cumulative_expenditure', 'cumulative_balance'], $zero);
        foreach ($keys as [$key, $fund]) {
            $current = $periodTotals[$key] ?? ['income' => $zero, 'expense' => $zero];
            $cumulative = $cumulativeTotals[$key] ?? ['income' => $zero, 'expense' => $zero];
            $values = [
                'period_income' => $current['income'], 'period_expenditure' => $current['expense'], 'period_balance' => $current['income']->minus($current['expense']),
                'cumulative_income' => $cumulative['income'], 'cumulative_expenditure' => $cumulative['expense'], 'cumulative_balance' => $cumulative['income']->minus($cumulative['expense']),
            ];
            foreach ($values as $column => $value) {
                $grand[$column] = $grand[$column]->plus($value);
            }
            $rows[] = [
                'fund' => $fund, 'label' => $fund?->label() ?? 'No fund', 'type' => $fund ? Fund::TYPES[$fund->type] ?? $fund->type : '—',
                'has_activity' => isset($cumulativeTotals[$key]),
                ...array_map(fn (BigDecimal $value): string => AccountingDecimal::string($value), $values),
            ];
        }

        return [
            'scope' => $scope, 'period' => $period, 'selected' => $selected, 'rows' => $rows,
            'totals' => array_map(fn (BigDecimal $value): string => AccountingDecimal::string($value), $grand),
            'has_activity' => $cumulativeTotals !== [],
        ];
    }

    /** @param array<string, mixed> $report */
    public function table(array $report): ReportTable
    {
        $rows = array_map(fn (array $row): array => ['fund' => $row['label'], 'type' => $row['type']] + array_intersect_key($row, array_flip(['period_income', 'period_expenditure', 'period_balance', 'cumulative_income', 'cumulative_expenditure', 'cumulative_balance'])), $report['rows']);
        $rows[] = ['fund' => $report['selected'] === null ? 'Total (all lines)' : 'Total of shown funds', ...$report['totals'], '_style' => 'total'];

        return new ReportTable('Fund Balance Summary', [
            ['key' => 'fund', 'label' => 'Fund'], ['key' => 'type', 'label' => 'Type'],
            ['key' => 'period_income', 'label' => 'Period income', 'type' => 'money'],
            ['key' => 'period_expenditure', 'label' => 'Period expenditure', 'type' => 'money'],
            ['key' => 'period_balance', 'label' => 'Period surplus/(deficit)', 'type' => 'money'],
            ['key' => 'cumulative_income', 'label' => 'Cumulative income', 'type' => 'money'],
            ['key' => 'cumulative_expenditure', 'label' => 'Cumulative expenditure', 'type' => 'money'],
            ['key' => 'cumulative_balance', 'label' => 'Fund balance', 'type' => 'money'],
        ], $rows, ['Period' => $report['period']['label'], 'Cumulative to' => $report['period']['to'], 'Branch scope' => $report['scope']->label, 'Basis' => 'Accrual, posted journals only'], self::METHOD);
    }

    /**
     * Income and expenditure per fund id (0 = no fund).
     *
     * @param  list<int>  $branchIds
     * @param  array<int, AccountType>  $types
     * @return array<int, array{income: BigDecimal, expense: BigDecimal}>
     */
    private function byFund(array $branchIds, ?string $from, string $to, array $types): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        $totals = [];
        foreach ($this->reader->totals($branchIds, $from, $to, [AccountType::Income, AccountType::Expense], LedgerActivityReader::BY_FUND, excludeClosing: true) as $row) {
            $key = $row['dimension'] ?? 0;
            $totals[$key] ??= ['income' => $zero, 'expense' => $zero];
            if (($types[$row['account_id']] ?? null) === AccountType::Income) {
                $totals[$key]['income'] = $totals[$key]['income']->minus($row['net']);
            } else {
                $totals[$key]['expense'] = $totals[$key]['expense']->plus($row['net']);
            }
        }

        return $totals;
    }
}
