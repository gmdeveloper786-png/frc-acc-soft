<?php

namespace App\Services\BankReconciliation;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/** Builds the bank reconciliation statement for export from the same figures the screen shows. */
class ReconciliationReport
{
    public const FORMULA = 'Balance as per bank statement + uncleared deposits − unpresented payments = balance as per bank book';

    public function __construct(private ReconciliationCalculator $calculator) {}

    public function table(BankReconciliation $reconciliation): ReportTable
    {
        $figures = $this->calculator->calculate($reconciliation);
        $money = fn (BigDecimal $value): string => AccountingDecimal::string($value);
        $rows = [['particulars' => 'Balance as per bank statement', 'date' => $reconciliation->statement_date->format('d M Y'), 'reference' => '', 'amount' => $money($figures['statement_balance']), '_style' => 'subtotal']];

        $rows[] = ['particulars' => 'Add: uncleared deposits (in the books, not yet on the statement)', 'date' => '', 'reference' => '', 'amount' => '', '_style' => 'heading'];
        array_push($rows, ...$this->bookRows($figures['uncleared']));
        $rows[] = ['particulars' => 'Total uncleared deposits', 'date' => '', 'reference' => '', 'amount' => $money($figures['uncleared_total']), '_style' => 'subtotal'];

        $rows[] = ['particulars' => 'Less: unpresented payments (in the books, not yet on the statement)', 'date' => '', 'reference' => '', 'amount' => '', '_style' => 'heading'];
        array_push($rows, ...$this->bookRows($figures['unpresented']));
        $rows[] = ['particulars' => 'Total unpresented payments', 'date' => '', 'reference' => '', 'amount' => $money($figures['unpresented_total']->negated()), '_style' => 'subtotal'];

        $rows[] = ['particulars' => 'Adjusted bank balance', 'date' => '', 'reference' => '', 'amount' => $money($figures['adjusted_balance']), '_style' => 'total'];
        $rows[] = ['particulars' => 'Balance as per bank book', 'date' => $reconciliation->statement_date->format('d M Y'), 'reference' => '', 'amount' => $money($figures['book_balance']), '_style' => 'total'];
        $rows[] = ['particulars' => 'Difference', 'date' => '', 'reference' => '', 'amount' => $money($figures['difference']), '_style' => 'total'];

        if ($figures['unrecorded']->isNotEmpty()) {
            $rows[] = ['particulars' => 'Statement items not yet recorded in the books (record through receipts/payments)', 'date' => '', 'reference' => '', 'amount' => '', '_style' => 'heading'];
            foreach ($figures['unrecorded'] as $line) {
                /** @var BankStatementLine $line */
                $rows[] = ['particulars' => (string) ($line->description ?? 'Statement line'), 'date' => $line->transaction_date->format('d M Y'), 'reference' => (string) ($line->reference ?? ''), 'amount' => AccountingDecimal::string(AccountingDecimal::of($line->amount)), '_indent' => 1];
            }
            $rows[] = ['particulars' => 'Total statement items not in the books', 'date' => '', 'reference' => '', 'amount' => $money($figures['unrecorded_total']), '_style' => 'subtotal'];
        }

        $account = $reconciliation->financialAccount;

        return new ReportTable(
            'Bank Reconciliation Statement',
            [
                ['key' => 'particulars', 'label' => 'Particulars', 'type' => 'text'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'text'],
                ['key' => 'reference', 'label' => 'Voucher / Reference', 'type' => 'text'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ],
            $rows,
            [
                'Bank account' => $account->name.($account->maskedNumber() ? ' ('.$account->maskedNumber().')' : ''),
                'Branch' => $account->branch->name,
                'Statement date' => $reconciliation->statement_date->format('d M Y'),
                'Status' => $reconciliation->statusLabel(),
            ],
            self::FORMULA.'.',
        );
    }

    /**
     * @param  Collection<int, JournalLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function bookRows(Collection $lines): array
    {
        return $lines->map(fn (JournalLine $line): array => [
            'particulars' => (string) ($line->description ?: $line->journalEntry->narration),
            'date' => $line->journalEntry->transaction_date->format('d M Y'),
            'reference' => trim($line->journalEntry->voucher_number.($line->journalEntry->reference_number ? ' / '.$line->journalEntry->reference_number : '')),
            'amount' => AccountingDecimal::string(Amount::journalLine($line->debit, $line->credit)->abs()),
            '_indent' => 1,
        ])->values()->all();
    }
}
