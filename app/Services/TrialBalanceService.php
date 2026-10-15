<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalLine;
use App\Services\Reports\Statements\LedgerActivityReader;
use Brick\Math\BigDecimal;

class TrialBalanceService
{
    public function __construct(private LedgerActivityReader $reader) {}

    /**
     * Balance-sheet ledgers carry forward every posted line dated before the
     * fiscal year; income and expenditure ledgers show only the selected year.
     * Earlier income and expenditure that was never closed is reported as
     * "prior_unclosed" (debit-positive) and counted in the totals.
     *
     * @param  list<int>  $branchIds
     */
    public function report(int $fiscalYearId, string $asOf, array $branchIds): array
    {
        $accounts = Account::query()->orderBy('sort_order')->orderBy('code')->get();
        $ledgerAmounts = [];
        $lines = JournalLine::query()->with('journalEntry')
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('fiscal_year_id', $fiscalYearId)
                ->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
                ->whereIn('branch_id', $branchIds)
                ->whereDate('transaction_date', '<=', $asOf))
            ->get();
        foreach ($lines as $line) {
            $ledgerAmounts[$line->account_id] = ($ledgerAmounts[$line->account_id] ?? BigDecimal::zero()->toScale(4))
                ->plus(AccountingDecimal::of($line->debit))->minus(AccountingDecimal::of($line->credit));
        }

        $priorUnclosed = BigDecimal::zero()->toScale(4);
        $carriedForward = false;
        $year = FiscalYear::query()->find($fiscalYearId);
        if ($year !== null) {
            $before = $year->start_date->copy()->subDay()->toDateString();
            foreach ($this->reader->totals($branchIds, null, $before, [AccountType::Asset, AccountType::Liability, AccountType::Equity]) as $row) {
                $carriedForward = true;
                $ledgerAmounts[$row['account_id']] = ($ledgerAmounts[$row['account_id']] ?? BigDecimal::zero()->toScale(4))->plus($row['net']);
            }
            foreach ($this->reader->totals($branchIds, null, $before, [AccountType::Income, AccountType::Expense]) as $row) {
                $priorUnclosed = $priorUnclosed->plus($row['net']);
            }
        }

        $children = $accounts->groupBy(fn (Account $account): int => (int) ($account->parent_id ?? 0));
        $amounts = [];
        $resolve = function (Account $account) use (&$resolve, &$amounts, $children, $ledgerAmounts): BigDecimal {
            if (isset($amounts[$account->id])) {
                return $amounts[$account->id];
            }
            $amount = $account->level === AccountLevel::Ledger
                ? ($ledgerAmounts[$account->id] ?? BigDecimal::zero()->toScale(4))
                : BigDecimal::zero()->toScale(4);
            foreach ($children->get($account->id, collect()) as $child) {
                $amount = $amount->plus($resolve($child));
            }

            return $amounts[$account->id] = $amount;
        };

        $rows = $accounts->map(function (Account $account) use ($resolve): array {
            $amount = $resolve($account);

            return [
                'account' => $account,
                'debit' => AccountingDecimal::string($amount->isPositive() ? $amount : BigDecimal::zero()->toScale(4)),
                'credit' => AccountingDecimal::string($amount->isNegative() ? $amount->abs() : BigDecimal::zero()->toScale(4)),
            ];
        });

        $ledgerRows = $rows->filter(fn (array $row): bool => $row['account']->level === AccountLevel::Ledger);
        $totalDebit = $ledgerRows->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['debit']), BigDecimal::zero()->toScale(4));
        $totalCredit = $ledgerRows->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['credit']), BigDecimal::zero()->toScale(4));
        if ($priorUnclosed->isPositive()) {
            $totalDebit = $totalDebit->plus($priorUnclosed);
        } elseif ($priorUnclosed->isNegative()) {
            $totalCredit = $totalCredit->plus($priorUnclosed->abs());
        }

        return [
            'rows' => $rows,
            'total_debit' => AccountingDecimal::string($totalDebit),
            'total_credit' => AccountingDecimal::string($totalCredit),
            'difference' => AccountingDecimal::string($totalDebit->minus($totalCredit)),
            'balanced' => $totalDebit->isEqualTo($totalCredit),
            'prior_unclosed' => AccountingDecimal::string($priorUnclosed),
            'carried_forward' => $carriedForward,
        ];
    }
}
