<?php

namespace App\Services;

use App\Enums\JournalStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\JournalLine;
use Brick\Math\BigDecimal;
use Illuminate\Pagination\LengthAwarePaginator;

class GeneralLedgerService
{
    /** @param list<int> $branchIds */
    public function report(Account $account, array $branchIds, string $from, string $to, ?int $serviceId, int $perPage = 25): array
    {
        $statuses = [JournalStatus::Posted->value, JournalStatus::Reversed->value];
        $base = JournalLine::query()
            ->with(['journalEntry.voucherType', 'journalEntry.branch', 'service'])
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $query->whereIn('status', $statuses)->whereIn('branch_id', $branchIds))
            ->when($serviceId, fn ($query) => $query->where('service_id', $serviceId));

        $openingLines = (clone $base)->whereHas('journalEntry', fn ($query) => $query->whereDate('transaction_date', '<', $from))->get();
        $opening = $this->signedTotal($openingLines, $account);
        $lines = $base->whereHas('journalEntry', fn ($query) => $query->whereBetween('transaction_date', [$from, $to]))
            ->get()->sortBy(fn (JournalLine $line): string => $line->journalEntry->transaction_date->format('Y-m-d').'-'.str_pad((string) $line->journal_entry_id, 20, '0', STR_PAD_LEFT).'-'.str_pad((string) $line->line_number, 5, '0', STR_PAD_LEFT))->values();

        $running = $opening;
        $rows = $lines->map(function (JournalLine $line) use (&$running, $account): array {
            $running = $running->plus($this->signed($line, $account));

            return [
                'date' => $line->journalEntry->transaction_date,
                'voucher_number' => $line->journalEntry->voucher_number,
                'reference' => $line->journalEntry->reference_number,
                'narration' => $line->description ?: $line->journalEntry->narration,
                'branch' => $line->journalEntry->branch->name,
                'service' => $line->service?->name,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'running_balance' => AccountingDecimal::string($running->abs()),
                'running_side' => $running->isNegative() ? $this->opposite($account) : $account->normal_balance->value,
            ];
        });

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage), $rows->count(), $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()]);

        return [
            'opening_balance' => AccountingDecimal::string($opening->abs()),
            'opening_side' => $opening->isNegative() ? $this->opposite($account) : $account->normal_balance->value,
            'closing_balance' => AccountingDecimal::string($running->abs()),
            'closing_side' => $running->isNegative() ? $this->opposite($account) : $account->normal_balance->value,
            'rows' => $paginator,
        ];
    }

    private function signedTotal(iterable $lines, Account $account): BigDecimal
    {
        $total = BigDecimal::zero()->toScale(4);
        foreach ($lines as $line) {
            $total = $total->plus($this->signed($line, $account));
        }

        return $total;
    }

    private function signed(JournalLine $line, Account $account): BigDecimal
    {
        $debit = AccountingDecimal::of($line->debit);
        $credit = AccountingDecimal::of($line->credit);

        return $account->normal_balance === NormalBalance::Debit ? $debit->minus($credit) : $credit->minus($debit);
    }

    private function opposite(Account $account): string
    {
        return $account->normal_balance === NormalBalance::Debit ? 'credit' : 'debit';
    }
}
