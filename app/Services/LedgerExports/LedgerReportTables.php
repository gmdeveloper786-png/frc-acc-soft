<?php

namespace App\Services\LedgerExports;

use App\Enums\AccountLevel;
use App\Enums\OperationalTransactionStatus;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use App\Models\PurchaseBill;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds export tables for the ledger and finance report screens. Every method
 * receives data that the controller has already authorized and branch-scoped;
 * nothing here widens a filter or recalculates a figure differently from the screen.
 */
class LedgerReportTables
{
    private const CHUNK = 500;

    /**
     * @param  array<string, mixed>  $ledger  Result of GeneralLedgerExportReader::read().
     * @param  array<string, string>  $meta
     */
    public function generalLedger(array $ledger, array $meta): ReportTable
    {
        $blank = ['voucher_number' => '', 'reference' => '', 'narration' => '', 'branch' => '', 'service' => '', 'debit' => null, 'credit' => null];
        $rows = [[...$blank, 'date' => 'Opening balance', 'running_balance' => $ledger['opening_balance'], 'running_side' => $this->sideLabel($ledger['opening_side']), '_style' => 'heading']];
        foreach ($ledger['rows'] as $row) {
            $rows[] = [...$row, 'running_side' => $this->sideLabel($row['running_side'])];
        }
        $rows[] = [...$blank, 'date' => 'Period totals', 'debit' => $ledger['total_debit'], 'credit' => $ledger['total_credit'], 'running_balance' => null, 'running_side' => '', '_style' => 'subtotal'];
        $rows[] = [...$blank, 'date' => 'Closing balance', 'running_balance' => $ledger['closing_balance'], 'running_side' => $this->sideLabel($ledger['closing_side']), '_style' => 'total'];

        return new ReportTable('General Ledger', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'voucher_number', 'label' => 'Voucher'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'narration', 'label' => 'Narration'],
            ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'service', 'label' => 'Service'],
            ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'],
            ['key' => 'credit', 'label' => 'Credit', 'type' => 'money'],
            ['key' => 'running_balance', 'label' => 'Running Balance', 'type' => 'money'],
            ['key' => 'running_side', 'label' => 'Dr/Cr'],
        ], $rows, $meta, 'Posted and reversed journals only. Draft, submitted and approved-but-unposted journals are excluded.');
    }

    /**
     * @param  array<string, mixed>  $report  Result of TrialBalanceService::report().
     * @param  array<string, string>  $meta
     */
    public function trialBalance(array $report, array $meta): ReportTable
    {
        $rows = [];
        foreach ($report['rows'] as $row) {
            $account = $row['account'];
            $rows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'level' => str($account->level->value)->headline()->toString(),
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                ...($account->level === AccountLevel::Ledger ? [] : ['_style' => 'heading']),
            ];
        }
        $priorUnclosed = AccountingDecimal::of($report['prior_unclosed'] ?? '0');
        if (! $priorUnclosed->isZero()) {
            $zero = AccountingDecimal::string(BigDecimal::zero()->toScale(4));
            $rows[] = [
                'code' => '', 'name' => 'Surplus/(deficit) of earlier years not yet closed', 'level' => 'Computed',
                'debit' => $priorUnclosed->isPositive() ? AccountingDecimal::string($priorUnclosed) : $zero,
                'credit' => $priorUnclosed->isNegative() ? AccountingDecimal::string($priorUnclosed->abs()) : $zero,
                '_style' => 'subtotal',
            ];
        }
        $rows[] = ['code' => 'Grand totals (ledgers only)', 'name' => '', 'level' => '', 'debit' => $report['total_debit'], 'credit' => $report['total_credit'], '_style' => 'total'];
        $notes = [$report['balanced']
            ? 'Parent balances roll up from ledger accounts; grand totals count each ledger once.'
            : 'Accounting integrity warning: Trial Balance difference is '.$report['difference'].'.'];
        if ($report['carried_forward'] ?? false) {
            $notes[] = 'Balance-sheet balances include amounts carried forward from earlier fiscal years; income and expenditure show the selected year only.';
        }

        return new ReportTable('Trial Balance', [
            ['key' => 'code', 'label' => 'Account Code'],
            ['key' => 'name', 'label' => 'Account Name'],
            ['key' => 'level', 'label' => 'Level'],
            ['key' => 'debit', 'label' => 'Debit Balance', 'type' => 'money'],
            ['key' => 'credit', 'label' => 'Credit Balance', 'type' => 'money'],
        ], $rows, $meta, implode(' ', $notes));
    }

    /**
     * @param  Builder<JournalLine>  $lines  The unordered, scoped query behind the book screen.
     * @param  array<string, string>  $meta
     * @param  array<string, string>  $accountNames  Cash/bank account name keyed by "ledger id:branch id".
     */
    public function book(string $kind, Builder $lines, array $meta, array $accountNames = []): ReportTable
    {
        $totalDebit = BigDecimal::zero()->toScale(4);
        $totalCredit = BigDecimal::zero()->toScale(4);
        $rows = [];
        foreach ($lines->lazyById(self::CHUNK) as $line) {
            $totalDebit = $totalDebit->plus(AccountingDecimal::of($line->debit));
            $totalCredit = $totalCredit->plus(AccountingDecimal::of($line->credit));
            $rows[] = [
                'date' => $line->journalEntry->transaction_date->format('d M Y'),
                'voucher' => $line->journalEntry->voucher_number,
                'branch' => $line->journalEntry->branch->name,
                'account' => $accountNames[$line->account_id.':'.$line->journalEntry->branch_id] ?? $line->account->name,
                'narration' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ];
        }
        if ($rows !== []) {
            $rows[] = ['date' => 'Totals', 'voucher' => '', 'branch' => '', 'account' => '', 'narration' => '', 'debit' => AccountingDecimal::string($totalDebit), 'credit' => AccountingDecimal::string($totalCredit), '_style' => 'total'];
        }

        return new ReportTable(ucfirst($kind).' Book', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'voucher', 'label' => 'Voucher'],
            ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'account', 'label' => 'Account'],
            ['key' => 'narration', 'label' => 'Narration'],
            ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'],
            ['key' => 'credit', 'label' => 'Credit', 'type' => 'money'],
        ], $rows, $meta, 'Posted journal lines only.');
    }

    /**
     * @param  Builder<OperationalTransaction>  $transactions  The scoped query behind the register screen.
     * @param  array<string, string>  $meta
     */
    public function register(string $kind, Builder $transactions, array $meta): ReportTable
    {
        $total = BigDecimal::zero()->toScale(4);
        $rows = [];
        foreach ($transactions->orderByDesc('transaction_date')->orderByDesc('id')->lazy(self::CHUNK) as $transaction) {
            $total = $total->plus(AccountingDecimal::of($transaction->amount));
            $rows[] = [
                'date' => $transaction->transaction_date->format('d M Y'),
                'voucher' => $transaction->journalEntry?->voucher_number,
                'type' => $transaction->paymentTypeLabel(),
                'branch' => $transaction->branch->name,
                'party' => $transaction->vendor?->name ?: $transaction->party_name,
                'amount' => $transaction->amount,
            ];
        }
        if ($rows !== []) {
            $rows[] = ['date' => 'Total', 'voucher' => '', 'type' => '', 'branch' => '', 'party' => '', 'amount' => AccountingDecimal::string($total), '_style' => 'total'];
        }

        return new ReportTable(str($kind)->title().' Register', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'voucher', 'label' => 'Voucher'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'party', 'label' => 'Party'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
        ], $rows, $meta, 'Posted records only.');
    }

    /**
     * @param  Collection<int, PurchaseBill>  $bills  Posted bills with `vendor`, `branch` and `payments` loaded.
     * @param  array<string, string>  $meta
     */
    public function vendorLedger(Collection $bills, array $meta): ReportTable
    {
        $totals = ['bill' => BigDecimal::zero()->toScale(4), 'paid' => BigDecimal::zero()->toScale(4), 'outstanding' => BigDecimal::zero()->toScale(4)];
        $rows = [];
        foreach ($bills as $bill) {
            $billAmount = AccountingDecimal::of($bill->total_amount);
            $paid = $this->paid($bill);
            $outstanding = $billAmount->minus($paid);
            $totals = ['bill' => $totals['bill']->plus($billAmount), 'paid' => $totals['paid']->plus($paid), 'outstanding' => $totals['outstanding']->plus($outstanding)];
            $rows[] = [
                'date' => $bill->invoice_date->format('d M Y'),
                'bill' => $bill->bill_number,
                'vendor' => $bill->vendor->name,
                'branch' => $bill->branch->name,
                'bill_amount' => AccountingDecimal::string($billAmount),
                'paid' => AccountingDecimal::string($paid),
                'outstanding' => AccountingDecimal::string($outstanding),
            ];
        }
        if ($rows !== []) {
            $rows[] = ['date' => 'Totals', 'bill' => '', 'vendor' => '', 'branch' => '', 'bill_amount' => AccountingDecimal::string($totals['bill']), 'paid' => AccountingDecimal::string($totals['paid']), 'outstanding' => AccountingDecimal::string($totals['outstanding']), '_style' => 'total'];
        }

        return new ReportTable('Vendor Ledger', [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'bill', 'label' => 'Bill'],
            ['key' => 'vendor', 'label' => 'Vendor'],
            ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'bill_amount', 'label' => 'Bill amount', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Paid', 'type' => 'money'],
            ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money'],
        ], $rows, $meta, 'Posted bills and posted payments only.');
    }

    /**
     * @param  Collection<int, PurchaseBill>  $bills  Outstanding posted bills, as shown on the screen.
     * @param  Collection<int, PayableObligation>  $obligations  Outstanding accrued expenses, as shown on the screen.
     * @param  Collection<string, BigDecimal>  $aging  Outstanding total per aging bucket, as shown on the screen.
     * @param  callable(PurchaseBill): string  $bucket
     * @param  callable(PayableObligation): string  $obligationBucket
     * @param  array<string, string>  $meta
     */
    public function payables(Collection $bills, Collection $obligations, Collection $aging, callable $bucket, callable $obligationBucket, array $meta): ReportTable
    {
        $total = BigDecimal::zero()->toScale(4);
        $rows = [];
        foreach ($bills as $bill) {
            $outstanding = AccountingDecimal::of($bill->getAttribute('report_outstanding') ?? $bill->outstandingAmount());
            $total = $total->plus($outstanding);
            $rows[] = [
                'vendor' => $bill->vendor->name,
                'bill' => $bill->bill_number,
                'due' => $bill->due_date?->format('d M Y') ?? '',
                'branch' => $bill->branch->name,
                'aging' => $bucket($bill),
                'outstanding' => AccountingDecimal::string($outstanding),
            ];
        }
        foreach ($obligations as $obligation) {
            $outstanding = AccountingDecimal::of($obligation->getAttribute('report_outstanding') ?? $obligation->outstandingAmount());
            $total = $total->plus($outstanding);
            $sourceReference = $obligation->sourceExpense?->reference_number ?: $obligation->source_reference;
            $rows[] = [
                'vendor' => $obligation->vendor?->name ?? 'Unassigned vendor',
                'bill' => 'Accrued expense #'.$obligation->source_expense_transaction_id.($sourceReference ? ' - '.$sourceReference : ''),
                'due' => $obligation->obligation_date->format('d M Y'),
                'branch' => $obligation->branch->name,
                'aging' => $obligationBucket($obligation),
                'outstanding' => AccountingDecimal::string($outstanding),
            ];
        }
        if ($rows !== []) {
            $rows[] = ['vendor' => 'Total outstanding', 'bill' => '', 'due' => '', 'branch' => '', 'aging' => '', 'outstanding' => AccountingDecimal::string($total), '_style' => 'total'];
            foreach (['Current', '1-30', '31-60', '61-90', '90+'] as $label) {
                $rows[] = ['vendor' => 'Aging '.$label.' days', 'bill' => '', 'due' => '', 'branch' => '', 'aging' => $label, 'outstanding' => AccountingDecimal::string($aging->get($label) ?? BigDecimal::zero()->toScale(4)), '_style' => 'subtotal'];
            }
        }

        return new ReportTable('Accounts Payable Outstanding & Aging', [
            ['key' => 'vendor', 'label' => 'Vendor'],
            ['key' => 'bill', 'label' => 'Bill'],
            ['key' => 'due', 'label' => 'Due'],
            ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'aging', 'label' => 'Aging (days)'],
            ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money'],
        ], $rows, $meta, 'Posted purchase bills and accrued expenses, less posted settlements.', [
            // Long vendor/source/branch values need real row heights in the PDF only.
            'wrap_rows' => true,
            'line_height' => 10,
            'cell_padding' => 4,
        ]);
    }

    /** Posted payments against a bill, from the already loaded relation. */
    private function paid(PurchaseBill $bill): BigDecimal
    {
        return $bill->payments
            ->filter(fn (OperationalTransaction $payment): bool => $payment->status === OperationalTransactionStatus::Posted)
            ->reduce(fn (BigDecimal $sum, OperationalTransaction $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->amount)), BigDecimal::zero()->toScale(4));
    }

    private function sideLabel(string $side): string
    {
        return $side === 'debit' ? 'DR' : 'CR';
    }
}
