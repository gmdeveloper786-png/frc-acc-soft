<?php

namespace App\Services\Reports\Statements;

use App\Enums\AccountType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Balance Sheet / Statement of Financial Position as of a date. Read-only: the
 * accounting equation is checked and reported, never forced to balance.
 */
class BalanceSheetStatement
{
    public const UNCLOSED_LABEL = 'Accumulated surplus/(deficit) not yet closed';

    public const METHOD = 'Balances are all posted journal activity up to and including the as-of date (opening-balance journals included). "'.self::UNCLOSED_LABEL.'" is computed, not posted: all income minus all expenses posted up to the as-of date, because year-end closing entries may not have been posted.';

    public const BRANCH_LIMITATION = 'Branch view — not a complete balanced statement';

    public const OPENING_NOTICE = 'No posted opening-balance journal exists for this scope: historical opening data may be incomplete and this statement may not be financially complete.';

    public function __construct(private LedgerActivityReader $reader, private AccountTree $tree) {}

    /**
     * @param  array<string, mixed>  $filters  Keys: branch_id, as_of.
     * @return array<string, mixed>
     */
    public function build(User $actor, array $filters): array
    {
        $scope = StatementScope::resolve($actor, $filters['branch_id'] ?? null);
        $asOf = Carbon::parse($filters['as_of'] ?? now()->toDateString())->startOfDay();

        $movements = [];
        foreach ($this->reader->totals($scope->branchIds(), null, $asOf->toDateString(), AccountType::cases()) as $row) {
            $movements[$row['account_id']]['balance'] = $row['net'];
        }

        $accounts = Account::query()->orderBy('sort_order')->orderBy('code')->get();
        $sections = [];
        foreach (AccountType::cases() as $type) {
            $sections[$type->value] = $this->tree->build($accounts, $type, $movements, ['balance']);
        }
        $total = fn (AccountType $type): BigDecimal => BigDecimal::of($sections[$type->value]['totals']['balance']);

        $unclosed = $total(AccountType::Income)->minus($total(AccountType::Expense));
        $equityWithSurplus = $total(AccountType::Equity)->plus($unclosed);
        $liabilitiesAndEquity = $total(AccountType::Liability)->plus($equityWithSurplus);
        // Reported as found. A difference is never absorbed into any line.
        $difference = $total(AccountType::Asset)->minus($liabilitiesAndEquity);
        $balanced = $difference->isZero();

        $hasOpening = JournalEntry::query()
            ->where('source_type', 'opening_balance')
            ->where('status', JournalStatus::Posted->value)
            ->whereIn('branch_id', $scope->branchIds())
            ->whereDate('transaction_date', '<=', $asOf->toDateString())
            ->exists();

        return [
            'scope' => $scope, 'as_of' => $asOf->toDateString(), 'as_of_label' => $asOf->format('d M Y'),
            'assets' => $sections[AccountType::Asset->value],
            'liabilities' => $sections[AccountType::Liability->value],
            'equity' => $sections[AccountType::Equity->value],
            'total_assets' => AccountingDecimal::string($total(AccountType::Asset)),
            'total_liabilities' => AccountingDecimal::string($total(AccountType::Liability)),
            'total_equity' => AccountingDecimal::string($total(AccountType::Equity)),
            'total_income' => AccountingDecimal::string($total(AccountType::Income)),
            'total_expenses' => AccountingDecimal::string($total(AccountType::Expense)),
            'unclosed_surplus' => AccountingDecimal::string($unclosed),
            'total_equity_with_surplus' => AccountingDecimal::string($equityWithSurplus),
            'total_liabilities_and_equity' => AccountingDecimal::string($liabilitiesAndEquity),
            'difference' => AccountingDecimal::string($difference),
            'balanced' => $balanced,
            // A branch (or partial) view that does not balance on its own is not a complete statement.
            'limitation' => ! $scope->consolidated && ! $balanced ? self::BRANCH_LIMITATION : null,
            'has_opening_balance' => $hasOpening,
            'has_activity' => $movements !== [],
        ];
    }

    /** @param array<string, mixed> $report */
    public function balanceResult(array $report): string
    {
        return $report['balanced']
            ? 'Balanced: Assets = Liabilities + Fund/Equity + unclosed surplus.'
            : 'OUT OF BALANCE by '.$report['difference'].' (Assets minus Liabilities, Fund/Equity and unclosed surplus). The difference is reported, not adjusted.';
    }

    /** @param array<string, mixed> $report */
    public function table(array $report): ReportTable
    {
        $line = fn (string $label, string $amount, string $style = 'total', int $indent = 0): array => ['account' => $label, 'balance' => $amount, '_style' => $style, '_indent' => $indent];
        $rows = [
            ['account' => 'Assets', '_style' => 'heading'],
            ...AccountTree::exportRows($report['assets']),
            $line('Total Assets', $report['total_assets']),
            ['account' => 'Liabilities', '_style' => 'heading'],
            ...AccountTree::exportRows($report['liabilities']),
            $line('Total Liabilities', $report['total_liabilities']),
            ['account' => 'Fund/Equity', '_style' => 'heading'],
            ...AccountTree::exportRows($report['equity']),
            $line(self::UNCLOSED_LABEL.' (computed)', $report['unclosed_surplus'], 'subtotal', 1),
            $line('Total Fund/Equity', $report['total_equity_with_surplus']),
            $line('Total Liabilities and Fund/Equity', $report['total_liabilities_and_equity']),
            $line('Difference (Assets minus Liabilities and Fund/Equity)', $report['difference']),
        ];

        $notes = [$this->balanceResult($report)];
        if ($report['limitation'] !== null) {
            $notes[] = $report['limitation'].'.';
        }
        if (! $report['has_opening_balance']) {
            $notes[] = self::OPENING_NOTICE;
        }
        $notes[] = self::METHOD;

        return new ReportTable(
            'Balance Sheet / Statement of Financial Position',
            [['key' => 'account', 'label' => 'Account'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            $rows,
            ['As of' => $report['as_of_label'], 'Branch scope' => $report['scope']->label, 'View' => $report['scope']->consolidated ? 'Consolidated' : 'Branch view', 'Basis' => 'Accrual, posted journals only'],
            implode(' ', $notes),
        );
    }
}
