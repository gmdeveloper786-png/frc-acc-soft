<?php

namespace App\Services\Funds;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Fund;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use App\Services\Reports\Statements\LedgerActivityReader;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Grant utilisation per grant fund and the donor report of one grant, from
 * the posted journal lines tagged with the grant fund, cumulative up to the
 * "as of" date. A grant without an amount shows no utilisation or remaining
 * balance rather than an invented figure. Read-only.
 */
class GrantUtilisationReport
{
    public const METHOD = 'Posted journals only, cumulative from the first posting up to the "as of" date, attributed by the fund recorded on each journal line. Income received = credits minus debits on income ledgers; expenditure charged = debits minus credits on expense ledgers. Utilisation = expenditure charged / grant amount. Remaining balance = grant amount - expenditure charged. Funds in hand = income received - expenditure charged. Year-end closing journals (and their reversals) are excluded.';

    public function __construct(private LedgerActivityReader $reader) {}

    /**
     * @param  array<string, mixed>  $filters  Keys: branch_id, as_of, fund_id.
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters): array
    {
        $scope = FundReportScope::resolve($actor, $filters['branch_id'] ?? null);
        $asOf = $this->asOf($filters);
        $selected = FundFilter::funds($filters['fund_id'] ?? null);
        $grants = ($selected ?? Fund::query()->orderBy('code')->get())->filter(fn (Fund $fund): bool => $fund->isGrant())->values();
        $ledgers = $this->ledgerTotals($scope->branchIds(), $asOf, $grants->modelKeys());

        $rows = $grants->map(function (Fund $fund) use ($ledgers): array {
            $income = $this->sum($ledgers[$fund->id]['income'] ?? []);
            $expenditure = $this->sum($ledgers[$fund->id]['expense'] ?? []);

            return ['fund' => $fund, ...$this->figures($fund, $income, $expenditure)];
        })->all();

        return ['scope' => $scope, 'as_of' => $asOf, 'selected' => $selected, 'rows' => $rows];
    }

    /**
     * Donor report of one grant: details, income received and expenditure
     * charged by ledger, utilisation and balance.
     *
     * @param  array<string, mixed>  $filters  Keys: branch_id, as_of.
     * @return array<string, mixed>
     */
    public function donorReport(User $actor, Fund $fund, array $filters): array
    {
        abort_unless($fund->isGrant(), 404);
        $scope = FundReportScope::resolve($actor, $filters['branch_id'] ?? null);
        $asOf = $this->asOf($filters);
        $ledgers = $this->ledgerTotals($scope->branchIds(), $asOf, [$fund->id])[$fund->id] ?? ['income' => [], 'expense' => []];
        $income = $ledgers['income'] ?? [];
        $expense = $ledgers['expense'] ?? [];

        return [
            'scope' => $scope, 'as_of' => $asOf, 'fund' => $fund,
            'income_lines' => array_values(array_map(fn (array $line): array => [...$line, 'amount' => AccountingDecimal::string($line['amount'])], $income)),
            'expense_lines' => array_values(array_map(fn (array $line): array => [...$line, 'amount' => AccountingDecimal::string($line['amount'])], $expense)),
            ...$this->figures($fund, $this->sum($income), $this->sum($expense)),
        ];
    }

    /** @param array<string, mixed> $report */
    public function table(array $report): ReportTable
    {
        $rows = array_map(fn (array $row): array => [
            'grant' => $row['fund']->label(), 'donor' => $row['fund']->donor_name ?? '—', 'period' => $row['period'],
            'grant_amount' => $row['grant_amount'], 'income' => $row['income'], 'expenditure' => $row['expenditure'],
            'utilisation' => $row['utilisation'], 'remaining' => $row['remaining'], 'in_hand' => $row['in_hand'],
        ], $report['rows']);

        return new ReportTable('Grant Utilisation', [
            ['key' => 'grant', 'label' => 'Grant fund'], ['key' => 'donor', 'label' => 'Donor / grantor'], ['key' => 'period', 'label' => 'Grant period'],
            ['key' => 'grant_amount', 'label' => 'Grant amount', 'type' => 'money'],
            ['key' => 'income', 'label' => 'Income received', 'type' => 'money'],
            ['key' => 'expenditure', 'label' => 'Expenditure charged', 'type' => 'money'],
            ['key' => 'utilisation', 'label' => 'Utilisation %', 'type' => 'percent'],
            ['key' => 'remaining', 'label' => 'Remaining balance', 'type' => 'money'],
            ['key' => 'in_hand', 'label' => 'Funds in hand', 'type' => 'money'],
        ], $rows, ['As of' => Carbon::parse($report['as_of'])->format('d M Y'), 'Branch scope' => $report['scope']->label, 'Basis' => 'Accrual, posted journals only'], self::METHOD);
    }

    /** @param array<string, mixed> $report */
    public function donorTable(array $report): ReportTable
    {
        $rows = [['item' => 'Income received', '_style' => 'heading']];
        foreach ($report['income_lines'] as $line) {
            $rows[] = ['item' => $line['code'].' '.$line['name'], 'amount' => $line['amount'], '_indent' => 1];
        }
        $rows[] = ['item' => 'Total income received', 'amount' => $report['income'], '_style' => 'total'];
        $rows[] = ['item' => 'Expenditure charged', '_style' => 'heading'];
        foreach ($report['expense_lines'] as $line) {
            $rows[] = ['item' => $line['code'].' '.$line['name'], 'amount' => $line['amount'], '_indent' => 1];
        }
        $rows[] = ['item' => 'Total expenditure charged', 'amount' => $report['expenditure'], '_style' => 'total'];
        $rows[] = ['item' => 'Funds in hand (income received - expenditure charged)', 'amount' => $report['in_hand'], '_style' => 'subtotal'];
        $rows[] = ['item' => 'Grant amount', 'amount' => $report['grant_amount']];
        $rows[] = ['item' => 'Utilisation %', 'amount' => $report['utilisation']];
        $rows[] = ['item' => 'Remaining grant balance (grant amount - expenditure charged)', 'amount' => $report['remaining'], '_style' => 'total'];

        $fund = $report['fund'];

        return new ReportTable('Donor Report: '.$fund->label(), [['key' => 'item', 'label' => 'Item'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']], $rows, [
            'Donor / grantor' => $fund->donor_name ?? 'Not recorded', 'Grant period' => $report['period'],
            'As of' => Carbon::parse($report['as_of'])->format('d M Y'), 'Branch scope' => $report['scope']->label,
        ], self::METHOD);
    }

    /** @return array{period: string, grant_amount: string|null, income: string, expenditure: string, utilisation: string|null, remaining: string|null, in_hand: string, has_activity: bool} */
    private function figures(Fund $fund, ?BigDecimal $income, ?BigDecimal $expenditure): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        $grant = $fund->grant_amount !== null ? AccountingDecimal::of($fund->grant_amount) : null;
        $spent = $expenditure ?? $zero;
        $received = $income ?? $zero;

        return [
            'period' => $fund->start_date || $fund->end_date
                ? ($fund->start_date?->format('d M Y') ?? 'Open').' to '.($fund->end_date?->format('d M Y') ?? 'open')
                : 'Not recorded',
            'grant_amount' => $grant !== null ? AccountingDecimal::string($grant) : null,
            'income' => AccountingDecimal::string($received),
            'expenditure' => AccountingDecimal::string($spent),
            'utilisation' => $grant !== null && $grant->isPositive() ? (string) $spent->multipliedBy(100)->dividedBy($grant, 2, RoundingMode::HalfUp) : null,
            'remaining' => $grant !== null ? AccountingDecimal::string($grant->minus($spent)) : null,
            'in_hand' => AccountingDecimal::string($received->minus($spent)),
            'has_activity' => $income !== null || $expenditure !== null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function asOf(array $filters): string
    {
        return filled($filters['as_of'] ?? null) ? Carbon::parse($filters['as_of'])->toDateString() : now()->toDateString();
    }

    /**
     * Presentation amounts per fund, section and ledger.
     *
     * @param  list<int>  $branchIds
     * @param  list<int>  $fundIds
     * @return array<int, array<string, array<int, array{code: string, name: string, amount: BigDecimal}>>>
     */
    private function ledgerTotals(array $branchIds, string $asOf, array $fundIds): array
    {
        if ($fundIds === []) {
            return [];
        }
        $accounts = Account::query()->whereIn('account_type', [AccountType::Income->value, AccountType::Expense->value])->orderBy('sort_order')->orderBy('code')->get()->keyBy('id');
        $totals = [];
        foreach ($this->reader->totals($branchIds, null, $asOf, [AccountType::Income, AccountType::Expense], LedgerActivityReader::BY_FUND, excludeClosing: true) as $row) {
            if (! in_array($row['dimension'], $fundIds, true) || ! $accounts->has($row['account_id'])) {
                continue;
            }
            $account = $accounts[$row['account_id']];
            $isIncome = $account->account_type === AccountType::Income;
            $totals[$row['dimension']][$isIncome ? 'income' : 'expense'][$account->id] = ['code' => $account->code, 'name' => $account->name, 'amount' => $isIncome ? $row['net']->negated() : $row['net']];
        }
        // Keep the chart order of the ledgers.
        foreach ($totals as $fundId => $sections) {
            foreach ($sections as $section => $lines) {
                $totals[$fundId][$section] = $accounts->keys()->filter(fn (int $id): bool => isset($lines[$id]))->mapWithKeys(fn (int $id): array => [$id => $lines[$id]])->all();
            }
        }

        return $totals;
    }

    /** @param array<int, array{amount: BigDecimal}> $lines Null when nothing was posted. */
    private function sum(array $lines): ?BigDecimal
    {
        if ($lines === []) {
            return null;
        }

        return array_reduce($lines, fn (BigDecimal $sum, array $line): BigDecimal => $sum->plus($line['amount']), BigDecimal::zero()->toScale(4));
    }
}
