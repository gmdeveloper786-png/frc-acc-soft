<?php

namespace App\Services\LedgerExports;

use App\Enums\JournalStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\AccountingDecimal;
use App\Services\GeneralLedgerService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads a whole General Ledger range for export. It applies the same filters,
 * ordering and sign rules as GeneralLedgerService (the paginated screen) but
 * walks the lines in chunks instead of loading every model at once.
 *
 * @see GeneralLedgerService
 */
class GeneralLedgerExportReader
{
    private const CHUNK = 500;

    /**
     * @param  list<int>  $branchIds
     * @return array{
     *     opening_balance: string, opening_side: string, closing_balance: string, closing_side: string,
     *     total_debit: string, total_credit: string,
     *     rows: list<array{date: string, voucher_number: ?string, reference: ?string, narration: ?string, branch: string, service: ?string, debit: string, credit: string, running_balance: string, running_side: string}>
     * }
     */
    public function read(Account $account, array $branchIds, string $from, string $to, ?int $serviceId): array
    {
        $opening = $this->opening($account, $branchIds, $from, $serviceId);
        $running = $opening;
        $totalDebit = BigDecimal::zero()->toScale(4);
        $totalCredit = BigDecimal::zero()->toScale(4);
        $rows = [];

        $lines = $this->base($account, $branchIds, $serviceId)
            ->select('journal_lines.*')
            ->with(['journalEntry.branch', 'service'])
            ->whereBetween('journal_entries.transaction_date', [$from, $to])
            ->orderBy('journal_entries.transaction_date')
            ->orderBy('journal_lines.journal_entry_id')
            ->orderBy('journal_lines.line_number')
            ->orderBy('journal_lines.id')
            ->lazy(self::CHUNK);

        foreach ($lines as $line) {
            $debit = AccountingDecimal::of($line->debit);
            $credit = AccountingDecimal::of($line->credit);
            $running = $running->plus($this->signed($debit, $credit, $account));
            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
            $rows[] = [
                'date' => $line->journalEntry->transaction_date->format('d M Y'),
                'voucher_number' => $line->journalEntry->voucher_number,
                'reference' => $line->journalEntry->reference_number,
                'narration' => $line->description ?: $line->journalEntry->narration,
                'branch' => $line->journalEntry->branch->name,
                'service' => $line->service?->name,
                'debit' => AccountingDecimal::string($debit),
                'credit' => AccountingDecimal::string($credit),
                'running_balance' => AccountingDecimal::string($running->abs()),
                'running_side' => $this->side($running, $account),
            ];
        }

        return [
            'opening_balance' => AccountingDecimal::string($opening->abs()),
            'opening_side' => $this->side($opening, $account),
            'closing_balance' => AccountingDecimal::string($running->abs()),
            'closing_side' => $this->side($running, $account),
            'total_debit' => AccountingDecimal::string($totalDebit),
            'total_credit' => AccountingDecimal::string($totalCredit),
            'rows' => $rows,
        ];
    }

    /** @param list<int> $branchIds */
    private function opening(Account $account, array $branchIds, string $from, ?int $serviceId): BigDecimal
    {
        $sums = $this->base($account, $branchIds, $serviceId)
            ->whereDate('journal_entries.transaction_date', '<', $from)
            ->toBase()
            ->selectRaw('SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')
            ->first();

        return $this->signed($this->decimal($sums?->debit_total), $this->decimal($sums?->credit_total), $account);
    }

    /**
     * @param  list<int>  $branchIds
     * @return Builder<JournalLine>
     */
    private function base(Account $account, array $branchIds, ?int $serviceId): Builder
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('journal_entries.branch_id', $branchIds)
            ->when($serviceId, fn (Builder $query) => $query->where('journal_lines.service_id', $serviceId));
    }

    /** SQLite returns DECIMAL sums as floats; MySQL returns exact decimal strings. */
    private function decimal(mixed $value): BigDecimal
    {
        return AccountingDecimal::of(is_float($value) ? number_format($value, 4, '.', '') : $value);
    }

    private function signed(BigDecimal $debit, BigDecimal $credit, Account $account): BigDecimal
    {
        return $account->normal_balance === NormalBalance::Debit ? $debit->minus($credit) : $credit->minus($debit);
    }

    private function side(BigDecimal $balance, Account $account): string
    {
        $normal = $account->normal_balance;

        return $balance->isNegative()
            ? ($normal === NormalBalance::Debit ? NormalBalance::Credit->value : NormalBalance::Debit->value)
            : $normal->value;
    }
}
