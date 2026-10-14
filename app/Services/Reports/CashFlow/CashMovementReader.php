<?php

namespace App\Services\Reports\CashFlow;

use App\Enums\JournalStatus;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\ManualJournalGuard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads actual cash and bank movement from posted journal lines. It is the one
 * source for the Cash Flow Statement and the Receipts & Payments Statement and
 * never writes anything.
 *
 * Every counted journal that touches a cash ledger is split over its non-cash
 * counter ledgers by their signed amounts (credit less debit), which always add
 * up to the journal's net cash movement. A journal made only of cash ledgers
 * is an internal transfer and moves nothing in or out.
 */
class CashMovementReader
{
    /** The statuses the General Ledger and Trial Balance count as ledger activity. */
    public const LEDGER_STATUSES = [JournalStatus::Posted, JournalStatus::Reversed];

    public function __construct(private ManualJournalGuard $guard, private BranchAccess $branchAccess) {}

    /** @return list<int> Cash, bank and petty cash ledger ids. */
    public function cashLedgerIds(): array
    {
        return array_map(fn ($id): int => (int) $id, $this->guard->controlledAccountIds());
    }

    /**
     * Authorize a statement request and return its branch filter. A null
     * branch means the consolidated view, which needs `branches.view_all`.
     *
     * @return list<int>|null Null when every branch is included.
     */
    public function authorizedBranchIds(User $actor, ?Branch $branch): ?array
    {
        abort_unless($actor->is_active && $actor->can('financial_statements.view'), 403);
        if ($branch === null) {
            abort_unless($actor->can('branches.view_all'), 403);

            return null;
        }
        abort_unless($this->branchAccess->canAccess($actor, $branch), 403);

        return [(int) $branch->id];
    }

    /**
     * @param  list<int>|null  $branchIds  Null includes every branch.
     * @return array{cash_ledger_ids: list<int>, opening: BigDecimal, closing: BigDecimal, cash_accounts: array<int, array{opening: BigDecimal, closing: BigDecimal}>, counter: array<int, array{inflows: BigDecimal, outflows: BigDecimal}>, net_change: BigDecimal, transfers: array{count: int, amount: BigDecimal}, journal_count: int}
     */
    public function read(?array $branchIds, string $from, string $to): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        $cashIds = $this->cashLedgerIds();
        $result = [
            'cash_ledger_ids' => $cashIds, 'opening' => $zero, 'closing' => $zero, 'cash_accounts' => [], 'counter' => [],
            'net_change' => $zero, 'transfers' => ['count' => 0, 'amount' => $zero], 'journal_count' => 0,
        ];
        if ($cashIds === []) {
            return $result;
        }

        $opening = $this->balances($cashIds, $branchIds, '<', $from);
        $closing = $this->balances($cashIds, $branchIds, '<=', $to);
        foreach (array_unique([...array_keys($opening), ...array_keys($closing)]) as $accountId) {
            $result['cash_accounts'][$accountId] = ['opening' => $opening[$accountId] ?? $zero, 'closing' => $closing[$accountId] ?? $zero];
            $result['opening'] = $result['opening']->plus($opening[$accountId] ?? $zero);
            $result['closing'] = $result['closing']->plus($closing[$accountId] ?? $zero);
        }

        $isCash = array_fill_keys($cashIds, true);
        $rows = $this->counted($branchIds)
            ->whereDate('journal_entries.transaction_date', '>=', $from)
            ->whereDate('journal_entries.transaction_date', '<=', $to)
            ->whereIn('journal_lines.journal_entry_id', DB::table('journal_lines as cash_lines')->whereIn('cash_lines.account_id', $cashIds)->select('cash_lines.journal_entry_id'))
            ->groupBy('journal_lines.journal_entry_id', 'journal_lines.account_id', 'journal_entries.reversal_of_id')
            ->orderBy('journal_lines.journal_entry_id')
            ->selectRaw('journal_lines.journal_entry_id as journal_id, journal_lines.account_id as account_id, journal_entries.reversal_of_id as reversal_of_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->cursor();

        $journalId = null;
        $lines = [];
        $isReversal = false;
        foreach ($rows as $row) {
            if ($journalId !== null && (int) $row->journal_id !== $journalId) {
                $result = $this->apply($result, $lines, $isReversal, $isCash);
                $lines = [];
            }
            $journalId = (int) $row->journal_id;
            $isReversal = $row->reversal_of_id !== null;
            $lines[(int) $row->account_id] = ['debit' => $this->sum($row->debit), 'credit' => $this->sum($row->credit)];
        }
        if ($journalId !== null) {
            $result = $this->apply($result, $lines, $isReversal, $isCash);
        }

        return $result;
    }

    /**
     * Add one journal to the totals. A reversing journal reduces the side its
     * original was counted on, so a reversed payment lowers payments and is
     * never reported as a receipt.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, array{debit: BigDecimal, credit: BigDecimal}>  $lines
     * @param  array<int, bool>  $isCash
     * @return array<string, mixed>
     */
    private function apply(array $result, array $lines, bool $isReversal, array $isCash): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        $counterLines = array_diff_key($lines, $isCash);
        if ($counterLines === []) {
            $result['transfers']['count']++;
            foreach ($lines as $line) {
                $result['transfers']['amount'] = $result['transfers']['amount']->plus($line['debit']);
            }

            return $result;
        }

        $result['journal_count']++;
        foreach ($counterLines as $accountId => $line) {
            $part = $line['credit']->minus($line['debit']);
            if ($part->isZero()) {
                continue;
            }
            $result['counter'][$accountId] ??= ['inflows' => $zero, 'outflows' => $zero];
            if ($part->isPositive() === $isReversal) {
                $result['counter'][$accountId]['outflows'] = $result['counter'][$accountId]['outflows']->minus($part);
            } else {
                $result['counter'][$accountId]['inflows'] = $result['counter'][$accountId]['inflows']->plus($part);
            }
            $result['net_change'] = $result['net_change']->plus($part);
        }

        return $result;
    }

    /**
     * Net debit balance of each cash ledger up to a date.
     *
     * @param  list<int>  $cashIds
     * @param  list<int>|null  $branchIds
     * @return array<int, BigDecimal>
     */
    private function balances(array $cashIds, ?array $branchIds, string $operator, string $date): array
    {
        $sums = $this->counted($branchIds)
            ->whereDate('journal_entries.transaction_date', $operator, $date)
            ->whereIn('journal_lines.account_id', $cashIds)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as account_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get();

        $balances = [];
        foreach ($sums as $sum) {
            $balances[(int) $sum->account_id] = $this->sum($sum->debit)->minus($this->sum($sum->credit));
        }

        return $balances;
    }

    /**
     * Lines of journals that count as ledger activity, limited to the branches.
     *
     * @param  list<int>|null  $branchIds
     */
    private function counted(?array $branchIds): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.status', array_map(fn (JournalStatus $status): string => $status->value, self::LEDGER_STATUSES))
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('journal_entries.branch_id', $branchIds));
    }

    /** A database SUM of DECIMAL(19,4) columns as an exact four-decimal value. */
    private function sum(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }
}
