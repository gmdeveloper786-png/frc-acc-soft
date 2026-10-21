<?php

namespace App\Console\Commands;

use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Models\OperationalTransaction;
use App\Services\AccountingDecimal;
use App\Services\PayableObligationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reconcile-accrued-payable-obligations {--apply : Create only verified missing obligation metadata after explicit approval} {--reference= : Limit the reconciliation to one expense reference}')]
#[Description('Dry-run reconciliation of posted accrued expenses to payable obligations')]
class ReconcileAccruedPayableObligations extends Command
{
    public function handle(PayableObligationService $obligations): int
    {
        $expenses = OperationalTransaction::query()
            ->with(['journalEntry.lines', 'accruedPayableObligation'])
            ->where('type', OperationalTransactionType::Expense->value)
            ->where('status', OperationalTransactionStatus::Posted->value)
            ->where('settlement_type', 'accrued')
            ->when($this->option('reference'), fn ($query, string $reference) => $query->where('reference_number', $reference))
            ->orderBy('id')->get();

        $candidates = $expenses->filter(fn (OperationalTransaction $expense): bool => ! $expense->accruedPayableObligation)
            ->filter(fn (OperationalTransaction $expense): bool => $this->hasVerifiableLiabilityEntry($expense));

        $invalid = $expenses->filter(fn (OperationalTransaction $expense): bool => ! $expense->accruedPayableObligation)
            ->reject(fn (OperationalTransaction $expense): bool => $this->hasVerifiableLiabilityEntry($expense));

        $this->table(['Result', 'Count'], [
            ['Already reconciled', $expenses->count() - $candidates->count() - $invalid->count()],
            ['Verified candidates', $candidates->count()],
            ['Requires manual review', $invalid->count()],
        ]);
        foreach ($invalid as $expense) {
            $this->warn("Expense #{$expense->id} ({$expense->reference_number}) was not changed: its posted journal does not exactly match the accrued liability.");
        }

        if (! $this->option('apply')) {
            $this->info('Dry run only. No records were changed. Use --apply only after explicit authorization and a reviewed dry-run result.');

            return self::SUCCESS;
        }

        foreach ($candidates as $expense) {
            $obligations->createForPostedExpense($expense, $expense->journalEntry);
        }
        $this->info($candidates->count().' verified payable obligation record(s) created. Posted journals were not changed.');

        return self::SUCCESS;
    }

    private function hasVerifiableLiabilityEntry(OperationalTransaction $expense): bool
    {
        $journal = $expense->journalEntry;
        if (! $journal || $journal->status !== JournalStatus::Posted || ! $expense->payable_account_id || ! $expense->contra_account_id) {
            return false;
        }

        $expenseDebit = $journal->lines->where('account_id', $expense->contra_account_id)->sum('debit');
        $payableCredit = $journal->lines->where('account_id', $expense->payable_account_id)->sum('credit');

        return AccountingDecimal::of($expenseDebit)->isEqualTo(AccountingDecimal::of($expense->amount))
            && AccountingDecimal::of($payableCredit)->isEqualTo(AccountingDecimal::of($expense->amount));
    }
}
