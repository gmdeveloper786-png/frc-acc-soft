<?php

namespace App\Services;

use App\Enums\AccountMappingKey;
use App\Models\AccountMapping;
use App\Models\FinancialAccount;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Collection;
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
        $lines = $journal->lines()->get();
        $this->assertFloatFundedOnlyByReplenishment($journal, $lines);
        $controlled = $this->controlledAccountIds();
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

    /**
     * A manual journal may not debit the petty cash ledger of an active imprest
     * float in its branch, unless it is the journal of an Imprest replenishment:
     * a float is only refilled through that controlled workflow. Crediting it is
     * already refused above, and reversals do not pass through this guard.
     *
     * @param  Collection<int, JournalLine>  $lines
     */
    private function assertFloatFundedOnlyByReplenishment(JournalEntry $journal, $lines): void
    {
        $debited = $lines->filter(fn ($line): bool => AccountingDecimal::of($line->debit)->isPositive())->pluck('account_id')->unique()->all();
        if ($debited === [] || ! ImprestFloat::query()->where('is_active', true)->where('branch_id', $journal->branch_id)->whereIn('account_id', $debited)->exists()) {
            return;
        }
        if (! ImprestReplenishment::query()->where('journal_entry_id', $journal->id)->exists()) {
            $this->audit->log('journal.imprest_funding_blocked', $journal, ['status' => $journal->status->value]);
            throw ValidationException::withMessages(['lines' => 'A petty cash imprest float can only be refilled through Imprest replenishment, not by a manual journal.']);
        }
    }
}
