<?php

namespace App\Services;

use App\Enums\AccountMappingKey;
use App\Models\AccountMapping;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use Illuminate\Validation\ValidationException;

/**
 * Stops a manual journal from paying money out of cash or bank. Outflows must
 * go through the payment workflows, where the approval threshold and Director
 * authority apply. Journals built by trusted server-side services are posted
 * through JournalPostingService::postSystem() and never reach this guard.
 */
class ManualJournalGuard
{
    public function __construct(private AuditLogger $audit) {}

    /** @return list<int> Ledger ids that represent cash, bank or petty cash. */
    public function controlledAccountIds(): array
    {
        return FinancialAccount::query()->pluck('account_id')
            ->merge(AccountMapping::query()->where('mapping_key', AccountMappingKey::PettyCash->value)->pluck('account_id'))
            ->unique()->values()->all();
    }

    /**
     * A manual journal may move money between cash and bank ledgers (contra
     * transfer) or debit them, but may not credit one against any other ledger.
     */
    public function assertAllowed(JournalEntry $journal): void
    {
        $controlled = $this->controlledAccountIds();
        $lines = $journal->lines()->get();
        $touching = $lines->whereIn('account_id', $controlled);
        if ($touching->isEmpty() || $touching->count() === $lines->count()) {
            return;
        }
        $credited = $touching->filter(fn ($line): bool => AccountingDecimal::of($line->credit)->isPositive());
        if ($credited->isEmpty()) {
            return;
        }

        $this->audit->log('journal.cash_bank_credit_blocked', $journal, ['account_ids' => $credited->pluck('account_id')->unique()->values()->all(), 'status' => $journal->status->value]);
        throw ValidationException::withMessages(['lines' => 'A manual journal cannot credit a cash, bank or petty cash ledger against another account. Record the payment through the payment workflow, or reverse the original entry. Transfers between cash and bank ledgers are allowed.']);
    }
}
