<?php

namespace App\Services\BankReconciliation;

use App\Enums\JournalStatus;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The bank book of one bank account: posted and reversed journal lines on the
 * account's ledger whose journal belongs to the account's own branch. A ledger
 * may back one account per branch, so the branch is always part of the rule.
 */
class BankBook
{
    /** @return Builder<JournalLine> */
    public function lines(FinancialAccount $account, ?CarbonInterface $upTo = null): Builder
    {
        return JournalLine::query()->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->account_id)
            ->where('journal_entries.branch_id', $account->branch_id)
            ->whereIn('journal_entries.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->when($upTo, fn (Builder $query) => $query->whereDate('journal_entries.transaction_date', '<=', $upTo->toDateString()));
    }

    public function contains(FinancialAccount $account, JournalLine $line, CarbonInterface $upTo): bool
    {
        return $this->lines($account, $upTo)->where('journal_lines.id', $line->id)->exists();
    }
}
