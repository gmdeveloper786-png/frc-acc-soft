<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalLine;
use Brick\Math\BigDecimal;

class TrialBalanceService
{
    /** @param list<int> $branchIds */
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

        return [
            'rows' => $rows,
            'total_debit' => AccountingDecimal::string($totalDebit),
            'total_credit' => AccountingDecimal::string($totalCredit),
            'difference' => AccountingDecimal::string($totalDebit->minus($totalCredit)),
            'balanced' => $totalDebit->isEqualTo($totalCredit),
        ];
    }
}
