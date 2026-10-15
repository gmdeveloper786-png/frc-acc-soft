<?php

namespace App\Services\BankReconciliation;

use App\Enums\JournalStatus;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Works out a reconciliation from the bank book and the imported statement:
 *
 *   statement balance + uncleared deposits − unpresented payments = book balance
 *
 * Uncleared deposits are book debits not yet on a statement, unpresented
 * payments are book credits not yet on a statement. Statement items not in the
 * books (bank charges, profit, direct credits) are listed for the accountant to
 * record through the normal receipt/payment workflow; until then they show up
 * as the difference. An approved reconciliation is read from its frozen summary.
 */
class ReconciliationCalculator
{
    public const MATCH_WINDOW_DAYS = 3;

    public function __construct(private BankBook $book) {}

    /**
     * @return array{
     *     frozen: bool,
     *     statement_balance: BigDecimal, book_balance: BigDecimal,
     *     uncleared: Collection<int, JournalLine>, uncleared_total: BigDecimal,
     *     unpresented: Collection<int, JournalLine>, unpresented_total: BigDecimal,
     *     unrecorded: Collection<int, BankStatementLine>, unrecorded_total: BigDecimal,
     *     reversal_pairs: Collection<int, JournalLine>,
     *     ignored: Collection<int, BankStatementLine>,
     *     matches: Collection<int, BankReconciliationMatch>,
     *     adjusted_balance: BigDecimal, difference: BigDecimal
     * }
     */
    public function calculate(BankReconciliation $reconciliation): array
    {
        if ($reconciliation->isApproved() && is_array($reconciliation->summary)) {
            return $this->frozen($reconciliation);
        }

        $account = $reconciliation->financialAccount;
        $date = $reconciliation->statement_date;
        $outstanding = $this->outstandingBookLines($reconciliation);
        $pairs = $this->reversalPairs($reconciliation, $outstanding);
        $outstanding = $outstanding->reject(fn (JournalLine $line): bool => $pairs->contains('id', $line->id))->values();
        $uncleared = $outstanding->filter(fn (JournalLine $line): bool => Amount::journalLine($line->debit, $line->credit)->isPositive())->values();
        $unpresented = $outstanding->filter(fn (JournalLine $line): bool => Amount::journalLine($line->debit, $line->credit)->isNegative())->values();

        $bookBalance = BigDecimal::zero();
        foreach ($this->book->lines($account, $date)->select(['journal_lines.debit', 'journal_lines.credit'])->toBase()->cursor() as $row) {
            $bookBalance = $bookBalance->plus(Amount::journalLine($row->debit, $row->credit));
        }

        return $this->result(
            false,
            AccountingDecimal::of($reconciliation->statement_closing_balance),
            $bookBalance,
            $uncleared,
            $unpresented,
            $this->unrecordedStatementLines($reconciliation),
            $pairs,
            BankStatementLine::query()->where('ignored_in_reconciliation_id', $reconciliation->id)->orderBy('transaction_date')->get(),
            $this->matches($reconciliation),
        );
    }

    /**
     * Book lines up to the statement date that no reconciliation has matched.
     *
     * @return Collection<int, JournalLine>
     */
    public function outstandingBookLines(BankReconciliation $reconciliation): Collection
    {
        return $this->book->lines($reconciliation->financialAccount, $reconciliation->statement_date)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('bank_reconciliation_matches')->whereColumn('bank_reconciliation_matches.journal_line_id', 'journal_lines.id'))
            ->with('journalEntry')->orderBy('journal_entries.transaction_date')->orderBy('journal_lines.id')->get();
    }

    /**
     * Unmatched statement lines of the account up to the statement date.
     *
     * @return Collection<int, BankStatementLine>
     */
    public function unrecordedStatementLines(BankReconciliation $reconciliation): Collection
    {
        return BankStatementLine::query()->where('financial_account_id', $reconciliation->financial_account_id)
            ->where('status', BankStatementLine::UNMATCHED)->whereDate('transaction_date', '<=', $reconciliation->statement_date->toDateString())
            ->orderBy('transaction_date')->orderBy('id')->get();
    }

    /**
     * Auto-match suggestions: same signed amount, dates at most three days apart
     * and, when asked, the same reference. Each line is suggested once; a matching
     * reference and then the closest date win.
     *
     * @return list<array{statement_line: BankStatementLine, journal_line: JournalLine, same_reference: bool, days_apart: int}>
     */
    public function suggestions(BankReconciliation $reconciliation, bool $requireReference = false): array
    {
        $statementLines = $this->unrecordedStatementLines($reconciliation);
        $bookLines = $this->outstandingBookLines($reconciliation);
        $candidates = [];
        foreach ($statementLines as $statementLine) {
            $amount = AccountingDecimal::of($statementLine->amount);
            foreach ($bookLines as $bookLine) {
                if (Amount::journalLine($bookLine->debit, $bookLine->credit)->compareTo($amount) !== 0) {
                    continue;
                }
                $days = (int) abs($statementLine->transaction_date->diffInDays($bookLine->journalEntry->transaction_date));
                if ($days > self::MATCH_WINDOW_DAYS) {
                    continue;
                }
                $sameReference = $this->sameReference($statementLine, $bookLine->journalEntry);
                if ($requireReference && ! $sameReference) {
                    continue;
                }
                $candidates[] = ['statement_line' => $statementLine, 'journal_line' => $bookLine, 'same_reference' => $sameReference, 'days_apart' => $days];
            }
        }
        usort($candidates, fn (array $a, array $b): int => [$b['same_reference'], $a['days_apart'], $a['statement_line']->id, $a['journal_line']->id]
            <=> [$a['same_reference'], $b['days_apart'], $b['statement_line']->id, $b['journal_line']->id]);

        $suggestions = [];
        $usedStatement = [];
        $usedBook = [];
        foreach ($candidates as $candidate) {
            if (isset($usedStatement[$candidate['statement_line']->id]) || isset($usedBook[$candidate['journal_line']->id])) {
                continue;
            }
            $usedStatement[$candidate['statement_line']->id] = true;
            $usedBook[$candidate['journal_line']->id] = true;
            $suggestions[] = $candidate;
        }

        return $suggestions;
    }

    /** @param array<string, mixed> $figures */
    public function summary(array $figures): array
    {
        return [
            'statement_balance' => AccountingDecimal::string($figures['statement_balance']),
            'book_balance' => AccountingDecimal::string($figures['book_balance']),
            'uncleared_total' => AccountingDecimal::string($figures['uncleared_total']),
            'unpresented_total' => AccountingDecimal::string($figures['unpresented_total']),
            'unrecorded_total' => AccountingDecimal::string($figures['unrecorded_total']),
            'adjusted_balance' => AccountingDecimal::string($figures['adjusted_balance']),
            'difference' => AccountingDecimal::string($figures['difference']),
            'uncleared_line_ids' => $figures['uncleared']->pluck('id')->all(),
            'unpresented_line_ids' => $figures['unpresented']->pluck('id')->all(),
            'unrecorded_statement_line_ids' => $figures['unrecorded']->pluck('id')->all(),
            'reversal_pair_line_ids' => $figures['reversal_pairs']->pluck('id')->all(),
        ];
    }

    private function sameReference(BankStatementLine $statementLine, JournalEntry $entry): bool
    {
        $normalize = fn (?string $value): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
        $statementReference = $normalize($statementLine->reference);
        $bookReferences = array_filter([$normalize($entry->reference_number), $normalize($entry->voucher_number)], fn (string $value): bool => strlen($value) >= 3);
        if ($bookReferences === []) {
            return false;
        }
        $description = $normalize($statementLine->description);
        foreach ($bookReferences as $reference) {
            if ($statementReference === $reference || ($statementReference !== '' && str_contains($statementReference, $reference)) || str_contains($description, $reference)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A reversed journal and its reversal that are both still outstanding cancel
     * each other out: neither will ever reach the bank, so neither is listed.
     *
     * @param  Collection<int, JournalLine>  $outstanding
     * @return Collection<int, JournalLine>
     */
    private function reversalPairs(BankReconciliation $reconciliation, Collection $outstanding): Collection
    {
        $byEntry = $outstanding->groupBy('journal_entry_id');
        $reversedIds = $outstanding->filter(fn (JournalLine $line): bool => $line->journalEntry->status === JournalStatus::Reversed)->pluck('journal_entry_id')->unique()->values();
        if ($reversedIds->isEmpty()) {
            return collect();
        }
        $reversalOf = JournalEntry::query()->whereIn('reversal_of_id', $reversedIds)->pluck('id', 'reversal_of_id');
        $entryIds = $reversalOf->keys()->merge($reversalOf->values())->all();
        $bookLineCounts = $this->book->lines($reconciliation->financialAccount, $reconciliation->statement_date)
            ->whereIn('journal_lines.journal_entry_id', $entryIds)->get()->countBy('journal_entry_id');

        $pairs = collect();
        foreach ($reversalOf as $originalId => $reversalId) {
            $lines = collect($byEntry->get($originalId, []))->merge($byEntry->get($reversalId, []));
            $complete = $byEntry->has($originalId) && $byEntry->has($reversalId)
                && $byEntry->get($originalId)->count() === ($bookLineCounts[$originalId] ?? 0)
                && $byEntry->get($reversalId)->count() === ($bookLineCounts[$reversalId] ?? 0);
            $net = $lines->reduce(fn (BigDecimal $sum, JournalLine $line): BigDecimal => $sum->plus(Amount::journalLine($line->debit, $line->credit)), BigDecimal::zero());
            if ($complete && $net->isZero()) {
                $pairs = $pairs->merge($lines);
            }
        }

        return $pairs->values();
    }

    /** @return Collection<int, BankReconciliationMatch> */
    private function matches(BankReconciliation $reconciliation): Collection
    {
        return $reconciliation->matches()->with(['statementLine', 'journalLine.journalEntry', 'matcher'])->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, JournalLine>  $uncleared
     * @param  Collection<int, JournalLine>  $unpresented
     * @param  Collection<int, BankStatementLine>  $unrecorded
     * @param  Collection<int, JournalLine>  $pairs
     * @param  Collection<int, BankStatementLine>  $ignored
     * @param  Collection<int, BankReconciliationMatch>  $matches
     */
    private function result(bool $frozen, BigDecimal $statementBalance, BigDecimal $bookBalance, Collection $uncleared, Collection $unpresented, Collection $unrecorded, Collection $pairs, Collection $ignored, Collection $matches): array
    {
        $sumBook = fn (Collection $lines): BigDecimal => $lines->reduce(fn (BigDecimal $sum, JournalLine $line): BigDecimal => $sum->plus(Amount::journalLine($line->debit, $line->credit)->abs()), BigDecimal::zero());
        $unclearedTotal = $sumBook($uncleared);
        $unpresentedTotal = $sumBook($unpresented);
        $unrecordedTotal = $unrecorded->reduce(fn (BigDecimal $sum, BankStatementLine $line): BigDecimal => $sum->plus(AccountingDecimal::of($line->amount)), BigDecimal::zero());
        $adjusted = $statementBalance->plus($unclearedTotal)->minus($unpresentedTotal);

        return [
            'frozen' => $frozen,
            'statement_balance' => $statementBalance, 'book_balance' => $bookBalance,
            'uncleared' => $uncleared, 'uncleared_total' => $unclearedTotal,
            'unpresented' => $unpresented, 'unpresented_total' => $unpresentedTotal,
            'unrecorded' => $unrecorded, 'unrecorded_total' => $unrecordedTotal,
            'reversal_pairs' => $pairs, 'ignored' => $ignored, 'matches' => $matches,
            'adjusted_balance' => $adjusted, 'difference' => $adjusted->minus($bookBalance),
        ];
    }

    private function frozen(BankReconciliation $reconciliation): array
    {
        $summary = $reconciliation->summary;
        $bookLines = fn (string $key): Collection => JournalLine::query()->with('journalEntry')->whereKey($summary[$key] ?? [])->get()
            ->sortBy(fn (JournalLine $line): string => $line->journalEntry->transaction_date->toDateString().'-'.str_pad((string) $line->id, 12, '0', STR_PAD_LEFT))->values();
        $result = $this->result(
            true,
            AccountingDecimal::of($summary['statement_balance']),
            AccountingDecimal::of($summary['book_balance']),
            $bookLines('uncleared_line_ids'),
            $bookLines('unpresented_line_ids'),
            BankStatementLine::query()->whereKey($summary['unrecorded_statement_line_ids'] ?? [])->orderBy('transaction_date')->orderBy('id')->get(),
            $bookLines('reversal_pair_line_ids'),
            BankStatementLine::query()->where('ignored_in_reconciliation_id', $reconciliation->id)->orderBy('transaction_date')->get(),
            $this->matches($reconciliation),
        );

        // The approved figures are the ones stored at approval, never recomputed.
        return [...$result,
            'uncleared_total' => AccountingDecimal::of($summary['uncleared_total']),
            'unpresented_total' => AccountingDecimal::of($summary['unpresented_total']),
            'unrecorded_total' => AccountingDecimal::of($summary['unrecorded_total']),
            'adjusted_balance' => AccountingDecimal::of($summary['adjusted_balance']),
            'difference' => AccountingDecimal::of($summary['difference']),
        ];
    }
}
