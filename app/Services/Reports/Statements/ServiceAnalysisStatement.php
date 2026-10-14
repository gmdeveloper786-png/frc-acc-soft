<?php

namespace App\Services\Reports\Statements;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\FrcService;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;

/**
 * Service-wise income, expenses and direct result, from the service recorded
 * on each posted journal line. Lines without a service stay in one
 * "Unallocated / shared" row and are never distributed. Read-only.
 */
class ServiceAnalysisStatement
{
    public const UNALLOCATED = 'Unallocated / shared';

    public const METHOD = 'Posted journals only, by transaction date, attributed by the service recorded on each journal line. The direct result is the direct result before unallocated shared costs, not profitability: income and expenses recorded without a service are shown as "Unallocated / shared" and are not distributed across services.';

    public function __construct(private LedgerActivityReader $reader, private StatementPeriod $periods) {}

    /**
     * @param  array<string, mixed>  $filters  Keys: branch_id, fiscal_year_id, from, to.
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters): array
    {
        $scope = StatementScope::resolve($actor, $filters['branch_id'] ?? null);
        $period = $this->periods->resolve([...$filters, 'compare' => null]);
        if ($period === null) {
            return ['scope' => $scope, 'period' => null];
        }

        $accounts = Account::query()->whereIn('account_type', [AccountType::Income->value, AccountType::Expense->value])->orderBy('sort_order')->orderBy('code')->get()->keyBy('id');
        $services = FrcService::query()->orderBy('name')->get();
        $zero = BigDecimal::zero()->toScale(4);

        // $amounts[income|expense][service id, 0 = no service][account id] = presentation amount
        $amounts = [AccountType::Income->value => [], AccountType::Expense->value => []];
        foreach ($this->reader->totals($scope->branchIds(), $period['from'], $period['to'], [AccountType::Income, AccountType::Expense], LedgerActivityReader::BY_SERVICE) as $row) {
            $type = $accounts[$row['account_id']]->account_type;
            $amounts[$type->value][$row['dimension'] ?? 0][$row['account_id']] = $type === AccountType::Income ? $row['net']->negated() : $row['net'];
        }

        $section = function (AccountType $type) use ($amounts, $accounts, $services, $zero): array {
            $group = function (int $key, string $name) use ($amounts, $accounts, $type, $zero): array {
                $ledgers = $amounts[$type->value][$key] ?? null;
                if ($ledgers === null) {
                    // Nothing was posted: the amount is absent rather than a reported zero.
                    return ['key' => $key, 'name' => $name, 'amount' => null, 'ledgers' => []];
                }
                $sum = $zero;
                $lines = [];
                foreach ($accounts as $account) {
                    if (isset($ledgers[$account->id])) {
                        $sum = $sum->plus($ledgers[$account->id]);
                        $lines[] = ['code' => $account->code, 'name' => $account->name, 'amount' => AccountingDecimal::string($ledgers[$account->id])];
                    }
                }

                return ['key' => $key, 'name' => $name, 'amount' => AccountingDecimal::string($sum), 'ledgers' => $lines];
            };
            $groups = $services->map(fn (FrcService $service): array => $group((int) $service->id, $service->name))->all();
            $unallocated = $group(0, self::UNALLOCATED);
            $allocated = array_reduce($groups, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['amount'] ?? '0'), $zero);

            return [
                'services' => $groups, 'unallocated' => $unallocated,
                'allocated_total' => AccountingDecimal::string($allocated),
                'total' => AccountingDecimal::string($allocated->plus($unallocated['amount'] ?? '0')),
            ];
        };

        $income = $section(AccountType::Income);
        $expenses = $section(AccountType::Expense);

        $summary = [];
        foreach ($services as $index => $service) {
            $direct = $income['services'][$index]['amount'];
            $cost = $expenses['services'][$index]['amount'];
            $summary[] = [
                'name' => $service->name, 'income' => $direct, 'expenses' => $cost,
                'result' => $direct === null && $cost === null ? null : AccountingDecimal::string(BigDecimal::of($direct ?? '0')->minus($cost ?? '0')),
            ];
        }

        return [
            'scope' => $scope, 'period' => $period, 'income' => $income, 'expenses' => $expenses, 'summary' => $summary,
            'direct_result_total' => AccountingDecimal::string(BigDecimal::of($income['allocated_total'])->minus($expenses['allocated_total'])),
            'overall_result' => AccountingDecimal::string(BigDecimal::of($income['total'])->minus($expenses['total'])),
            'has_activity' => $amounts[AccountType::Income->value] !== [] || $amounts[AccountType::Expense->value] !== [],
        ];
    }

    /** @param array<string, mixed> $report */
    public function table(array $report): ReportTable
    {
        $rows = [];
        foreach (['income' => 'Service-wise fee income', 'expenses' => 'Service-wise recorded expenses'] as $key => $title) {
            $rows[] = ['item' => $title, '_style' => 'heading'];
            foreach ([...$report[$key]['services'], $report[$key]['unallocated']] as $group) {
                $rows[] = ['item' => $group['name'].($group['amount'] === null ? ' (no posted activity)' : ''), $key => $group['amount'], '_style' => 'subtotal', '_indent' => 1];
                foreach ($group['ledgers'] as $ledger) {
                    $rows[] = ['item' => $ledger['code'].' '.$ledger['name'], $key => $ledger['amount'], '_indent' => 2];
                }
            }
            $rows[] = ['item' => $key === 'income' ? 'Total Income' : 'Total Expenses', $key => $report[$key]['total'], '_style' => 'total'];
        }

        $rows[] = ['item' => 'Service Performance Summary (direct result before unallocated shared costs)', '_style' => 'heading'];
        foreach ($report['summary'] as $line) {
            $rows[] = ['item' => $line['name'].($line['result'] === null ? ' (no posted activity)' : ''), 'income' => $line['income'], 'expenses' => $line['expenses'], 'result' => $line['result'], '_indent' => 1];
        }
        $rows[] = ['item' => 'Total attributed to services', 'income' => $report['income']['allocated_total'], 'expenses' => $report['expenses']['allocated_total'], 'result' => $report['direct_result_total'], '_style' => 'subtotal'];
        $rows[] = ['item' => self::UNALLOCATED.' (not distributed)', 'income' => $report['income']['unallocated']['amount'], 'expenses' => $report['expenses']['unallocated']['amount'], '_style' => 'subtotal'];
        $rows[] = ['item' => 'Overall surplus/(deficit) for the period', 'income' => $report['income']['total'], 'expenses' => $report['expenses']['total'], 'result' => $report['overall_result'], '_style' => 'total'];

        return new ReportTable('Service Analysis', [
            ['key' => 'item', 'label' => 'Service / account'],
            ['key' => 'income', 'label' => 'Direct income', 'type' => 'money'],
            ['key' => 'expenses', 'label' => 'Direct expenses', 'type' => 'money'],
            ['key' => 'result', 'label' => 'Direct result', 'type' => 'money'],
        ], $rows, ['Period' => $report['period']['label'], 'Branch scope' => $report['scope']->label, 'Basis' => 'Accrual, posted journals only'], self::METHOD);
    }
}
