<?php

namespace App\Services\PettyCash;

use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Models\FinancialAccount;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures of an imprest float, always derived from posted journal lines of the
 * float's petty cash ledger in the float's branch. Nothing here writes.
 */
class ImprestLedgerService
{
    /** Journal statuses whose lines are part of the ledger, as in the general ledger and trial balance. */
    public const LEDGER_STATUSES = [JournalStatus::Posted->value, JournalStatus::Reversed->value];

    /**
     * Posted lines of the float's ledger in its branch. A reversed journal stays in the
     * ledger next to its posted reversal, so the two net to zero.
     *
     * @return Builder<JournalLine>
     */
    public function ledgerLines(ImprestFloat $float): Builder
    {
        return JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $float->account_id)
            ->where('journal_entries.branch_id', $float->branch_id)
            ->whereIn('journal_entries.status', self::LEDGER_STATUSES);
    }

    /** Debit-normal balance of the petty cash ledger, optionally only before a date (exclusive). */
    public function balance(ImprestFloat $float, ?string $before = null): BigDecimal
    {
        $total = BigDecimal::zero()->toScale(4);
        $query = $this->ledgerLines($float)->when($before, fn (Builder $query) => $query->whereDate('journal_entries.transaction_date', '<', $before))
            ->toBase()->select(['journal_lines.debit', 'journal_lines.credit']);
        foreach ($query->cursor() as $line) {
            $total = $total->plus(AccountingDecimal::of($line->debit))->minus(AccountingDecimal::of($line->credit));
        }

        return $total;
    }

    /** Replenishment needed to restore the float: float − balance, never negative. */
    public function due(ImprestFloat $float, BigDecimal $balance): BigDecimal
    {
        $due = AccountingDecimal::of($float->float_amount)->minus($balance);

        return $due->isNegative() ? BigDecimal::zero()->toScale(4) : $due;
    }

    /**
     * Petty cash vouchers paid out of this float's ledger in its branch.
     *
     * @return Builder<OperationalTransaction>
     */
    public function vouchers(ImprestFloat $float): Builder
    {
        return OperationalTransaction::query()->where('type', OperationalTransactionType::PettyCash->value)
            ->where('branch_id', $float->branch_id)
            ->whereHas('financialAccount', fn (Builder $query) => $query->where('account_id', $float->account_id)->where('branch_id', $float->branch_id));
    }

    /**
     * Posted vouchers since the float was set up that no live replenishment covers yet.
     *
     * @return Collection<int, OperationalTransaction>
     */
    public function uncoveredVouchers(ImprestFloat $float): Collection
    {
        return $this->vouchers($float)->with('journalEntry')
            ->where('status', OperationalTransactionStatus::Posted->value)
            ->where('posted_at', '>=', $float->created_at)
            ->whereNotIn('id', $this->coveredVoucherIds($float))
            ->orderBy('transaction_date')->orderBy('id')->get();
    }

    /** Voucher statuses that hold part of the float until posted: in approval, approved or paid out. */
    public const RESERVING_STATUSES = [OperationalTransactionStatus::Submitted->value, OperationalTransactionStatus::Approved->value, OperationalTransactionStatus::Disbursed->value];

    /** The active float whose petty cash ledger and branch this cash account holds, if any. */
    public function activeFloatFor(FinancialAccount $account, bool $lock = false): ?ImprestFloat
    {
        return ImprestFloat::query()->where('is_active', true)->where('branch_id', $account->branch_id)->where('account_id', $account->account_id)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->first();
    }

    /**
     * Petty cash that can still be paid out: the posted ledger balance less the
     * vouchers in approval, approved or disbursed but not yet posted. Drafts and
     * returned vouchers reserve nothing (they are checked again when submitted);
     * posted ones are already in the balance. $exceptId leaves a voucher's own
     * reservation out when it is checked again.
     */
    public function available(ImprestFloat $float, ?int $exceptId = null): BigDecimal
    {
        $reserved = $this->vouchers($float)->whereIn('status', self::RESERVING_STATUSES)->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->pluck('amount')->reduce(fn (BigDecimal $sum, $amount): BigDecimal => $sum->plus(AccountingDecimal::of((string) $amount)), BigDecimal::zero()->toScale(4));

        return $this->balance($float)->minus($reserved);
    }

    /** @return Collection<int, OperationalTransaction> Vouchers not yet posted (draft, in approval, disbursed or returned). */
    public function pendingVouchers(ImprestFloat $float): Collection
    {
        return $this->vouchers($float)
            ->whereNotIn('status', [OperationalTransactionStatus::Posted->value, OperationalTransactionStatus::Reversed->value])
            ->orderBy('transaction_date')->orderBy('id')->get();
    }

    /** @return list<int> */
    public function coveredVoucherIds(ImprestFloat $float): array
    {
        return ImprestReplenishment::query()->where('imprest_float_id', $float->id)->where('status', '!=', ImprestReplenishment::CANCELLED)
            ->pluck('covered_voucher_ids')->flatten()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Bring each replenishment's status in line with its journal. A discarded draft
     * journal no longer exists, so its replenishment is cancelled.
     */
    public function syncStatuses(ImprestFloat $float): void
    {
        $replenishments = ImprestReplenishment::query()->with('journalEntry:id,status')->where('imprest_float_id', $float->id)
            ->where('status', '!=', ImprestReplenishment::CANCELLED)->get();
        foreach ($replenishments as $replenishment) {
            $status = $replenishment->journalEntry?->status->value ?? ImprestReplenishment::CANCELLED;
            if ($status !== $replenishment->status) {
                $replenishment->update(['status' => $status]);
            }
        }
    }

    /** Open (unposted) replenishment of the float, if any. */
    public function openReplenishment(ImprestFloat $float): ?ImprestReplenishment
    {
        return ImprestReplenishment::query()->where('imprest_float_id', $float->id)->whereIn('status', ImprestReplenishment::OPEN_STATUSES)->latest('id')->first();
    }

    /** @param Collection<int, OperationalTransaction> $vouchers */
    public function total(Collection $vouchers): BigDecimal
    {
        return $vouchers->reduce(fn (BigDecimal $sum, OperationalTransaction $voucher): BigDecimal => $sum->plus(AccountingDecimal::of($voucher->amount)), BigDecimal::zero()->toScale(4));
    }

    /**
     * Everything the imprest dashboard shows. Amounts are exact decimal strings.
     *
     * @return array{float: string, balance: string, due: string, posted_vouchers: Collection<int, OperationalTransaction>, posted_total: string, pending_vouchers: Collection<int, OperationalTransaction>, pending_total: string, warnings: list<string>, open_replenishment: ?ImprestReplenishment}
     */
    public function summary(ImprestFloat $float): array
    {
        $this->syncStatuses($float);
        $balance = $this->balance($float);
        $floatAmount = AccountingDecimal::of($float->float_amount);
        $posted = $this->uncoveredVouchers($float);
        $pending = $this->pendingVouchers($float);
        $warnings = [];
        if ($balance->isNegative()) {
            $warnings[] = 'The petty cash balance is negative: more has been paid out of this ledger than it holds. Check the vouchers and the replenishments.';
        }
        if ($balance->isGreaterThan($floatAmount)) {
            $warnings[] = 'The petty cash balance is above the imprest float. The ledger has received more than the float; review receipts and replenishments into it.';
        }

        return [
            'float' => AccountingDecimal::string($floatAmount),
            'balance' => AccountingDecimal::string($balance),
            'due' => AccountingDecimal::string($this->due($float, $balance)),
            'posted_vouchers' => $posted,
            'posted_total' => AccountingDecimal::string($this->total($posted)),
            'pending_vouchers' => $pending,
            'pending_total' => AccountingDecimal::string($this->total($pending)),
            'warnings' => $warnings,
            'open_replenishment' => $this->openReplenishment($float)?->load('journalEntry'),
        ];
    }
}
