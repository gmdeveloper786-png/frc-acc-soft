<?php

namespace App\Services\Reports\Statements;

use App\Enums\AccountType;
use App\Enums\JournalStatus;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads posted General Ledger activity for the financial statements. One
 * grouped query per call; the result is exact (no floating point arithmetic).
 * It never writes anything.
 */
class LedgerActivityReader
{
    public const BY_BRANCH = 'journal_entries.branch_id';

    public const BY_SERVICE = 'journal_lines.service_id';

    /** Source type of year-end closing journals (see YearEndClosingService). */
    public const YEAR_END_CLOSING = 'year_end_closing';

    /**
     * Net movement (debit minus credit) per account, optionally split by one
     * further dimension. Journals count when they are posted or reversed: a
     * reversed original keeps counting on its own date and its reversing
     * journal counts on its date.
     *
     * Set $excludeClosing for performance reporting (income and expenditure,
     * service analysis): year-end closing journals and their reversals only
     * move the result to the Accumulated Surplus ledger and are not activity.
     *
     * @param  list<int>  $branchIds
     * @param  list<AccountType>  $types
     * @param  self::BY_*|null  $dimension
     * @return list<array{account_id: int, dimension: int|null, net: BigDecimal}>
     */
    public function totals(array $branchIds, ?string $from, string $to, array $types, ?string $dimension = null, bool $excludeClosing = false): array
    {
        if ($branchIds === [] || $types === []) {
            return [];
        }

        // SQLite stores DECIMAL as a float, so there the sum is taken over exact
        // integer ten-thousandths. Other drivers sum the DECIMAL column exactly.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $sum = fn (string $column): string => $sqlite
            ? "SUM(CAST(ROUND(journal_lines.{$column} * 10000) AS INTEGER))"
            : "SUM(journal_lines.{$column})";

        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_entries.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('journal_entries.branch_id', $branchIds)
            ->whereIn('accounts.account_type', array_map(fn (AccountType $type): string => $type->value, $types))
            ->whereDate('journal_entries.transaction_date', '<=', $to)
            ->when($from !== null, fn ($builder) => $builder->whereDate('journal_entries.transaction_date', '>=', $from))
            ->when($excludeClosing, fn ($builder) => self::withoutYearEndClosing($builder))
            ->groupBy('journal_lines.account_id')
            ->selectRaw("journal_lines.account_id as account_id, {$sum('debit')} as debit_total, {$sum('credit')} as credit_total");

        if ($dimension !== null) {
            $query->groupBy($dimension)->selectRaw("{$dimension} as dimension_id");
        }

        $amount = fn (mixed $value): BigDecimal => $sqlite
            ? BigDecimal::ofUnscaledValue((string) (int) $value, 4)
            : AccountingDecimal::of($value);

        return $query->get()->map(fn (object $row): array => [
            'account_id' => (int) $row->account_id,
            'dimension' => $dimension !== null && $row->dimension_id !== null ? (int) $row->dimension_id : null,
            'net' => $amount($row->debit_total)->minus($amount($row->credit_total)),
        ])->all();
    }

    /**
     * Leave out year-end closing journals and the journals that reverse them
     * from a query joined to journal_entries. Used by performance reporting
     * only; balances (Balance Sheet, Trial Balance, General Ledger) keep them.
     */
    public static function withoutYearEndClosing(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $builder) => $builder->whereNull('journal_entries.source_type')->orWhere('journal_entries.source_type', '!=', self::YEAR_END_CLOSING))
            ->whereNotExists(fn (Builder $builder) => $builder->selectRaw('1')->from('journal_entries as closed_entries')
                ->whereColumn('closed_entries.id', 'journal_entries.reversal_of_id')
                ->where('closed_entries.source_type', self::YEAR_END_CLOSING));
    }
}
