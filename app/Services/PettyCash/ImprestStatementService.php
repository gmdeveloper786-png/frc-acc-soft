<?php

namespace App\Services\PettyCash;

use App\Enums\OperationalTransactionType;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;

/**
 * Imprest statement for a period: opening balance, every posted movement of the
 * petty cash ledger in the branch (vouchers, replenishments and anything else),
 * and the closing balance. The screen and the exports use the same figures.
 */
class ImprestStatementService
{
    public function __construct(private ImprestLedgerService $ledger) {}

    /**
     * @return array{opening: string, closing: string, vouchers_total: string, replenishments_total: string, other_debits: string, other_credits: string, rows: list<array{date: string, voucher: ?string, journal_id: int, kind: string, narration: string, receipt: string, payment: string, balance: string}>}
     */
    public function build(ImprestFloat $float, CarbonInterface $from, CarbonInterface $to): array
    {
        $opening = $this->ledger->balance($float, $from->toDateString());
        $replenishmentJournals = ImprestReplenishment::query()->where('imprest_float_id', $float->id)->whereNotNull('journal_entry_id')->pluck('journal_entry_id')->all();
        $lines = $this->ledger->ledgerLines($float)
            ->whereDate('journal_entries.transaction_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.transaction_date', '<=', $to->toDateString())
            ->orderBy('journal_entries.transaction_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->get(['journal_lines.id', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.description', 'journal_entries.id as journal_id',
                'journal_entries.voucher_number', 'journal_entries.transaction_date', 'journal_entries.source_type', 'journal_entries.narration']);

        $zero = BigDecimal::zero()->toScale(4);
        $totals = ['vouchers' => $zero, 'replenishments' => $zero, 'other_debits' => $zero, 'other_credits' => $zero];
        $balance = $opening;
        $rows = [];
        foreach ($lines as $line) {
            $debit = AccountingDecimal::of($line->debit);
            $credit = AccountingDecimal::of($line->credit);
            $balance = $balance->plus($debit)->minus($credit);
            $kind = match (true) {
                $line->source_type === OperationalTransactionType::PettyCash->value => 'Petty cash voucher',
                in_array((int) $line->journal_id, $replenishmentJournals, true) => 'Imprest replenishment',
                default => 'Other ledger entry',
            };
            if ($kind === 'Petty cash voucher') {
                $totals['vouchers'] = $totals['vouchers']->plus($credit)->minus($debit);
            } elseif ($kind === 'Imprest replenishment') {
                $totals['replenishments'] = $totals['replenishments']->plus($debit)->minus($credit);
            } else {
                $totals['other_debits'] = $totals['other_debits']->plus($debit);
                $totals['other_credits'] = $totals['other_credits']->plus($credit);
            }
            $rows[] = [
                'date' => substr((string) $line->transaction_date, 0, 10), 'voucher' => $line->voucher_number, 'journal_id' => (int) $line->journal_id,
                'kind' => $kind, 'narration' => (string) ($line->narration ?: $line->description),
                'receipt' => AccountingDecimal::string($debit), 'payment' => AccountingDecimal::string($credit), 'balance' => AccountingDecimal::string($balance),
            ];
        }

        return [
            'opening' => AccountingDecimal::string($opening), 'closing' => AccountingDecimal::string($balance),
            'vouchers_total' => AccountingDecimal::string($totals['vouchers']), 'replenishments_total' => AccountingDecimal::string($totals['replenishments']),
            'other_debits' => AccountingDecimal::string($totals['other_debits']), 'other_credits' => AccountingDecimal::string($totals['other_credits']),
            'rows' => $rows,
        ];
    }

    /** @param array{opening: string, closing: string, rows: list<array<string, mixed>>} $statement */
    public function table(ImprestFloat $float, CarbonInterface $from, CarbonInterface $to, array $statement): ReportTable
    {
        $columns = [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'voucher', 'label' => 'Voucher'], ['key' => 'kind', 'label' => 'Type'],
            ['key' => 'narration', 'label' => 'Narration'], ['key' => 'receipt', 'label' => 'Received', 'type' => 'money'],
            ['key' => 'payment', 'label' => 'Paid out', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'],
        ];
        $rows = [['date' => $from->toDateString(), 'voucher' => '', 'kind' => 'Opening balance', 'narration' => '', 'receipt' => '', 'payment' => '', 'balance' => $statement['opening'], '_style' => 'subtotal']];
        foreach ($statement['rows'] as $row) {
            $rows[] = ['date' => $row['date'], 'voucher' => $row['voucher'] ?? '', 'kind' => $row['kind'], 'narration' => $row['narration'], 'receipt' => $row['receipt'], 'payment' => $row['payment'], 'balance' => $row['balance']];
        }
        $rows[] = ['date' => $to->toDateString(), 'voucher' => '', 'kind' => 'Closing balance', 'narration' => '', 'receipt' => '', 'payment' => '', 'balance' => $statement['closing'], '_style' => 'total'];

        return new ReportTable('Petty Cash Imprest Statement', $columns, $rows, [
            'Branch' => (string) $float->branch?->name,
            'Petty cash ledger' => $float->account ? $float->account->code.' — '.$float->account->name : '',
            'Custodian' => (string) $float->custodian_name,
            'Float' => AccountingDecimal::string(AccountingDecimal::of($float->float_amount)),
            'From' => $from->format('d M Y'), 'To' => $to->format('d M Y'),
        ], 'Posted journal lines only. Replenishment journals are included once posted.');
    }
}
