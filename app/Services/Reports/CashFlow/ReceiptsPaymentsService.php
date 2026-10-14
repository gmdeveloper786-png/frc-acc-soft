<?php

namespace App\Services\Reports\CashFlow;

use App\Models\Account;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;

/**
 * Receipts & Payments Statement on the cash basis: only money that actually
 * moved through a cash or bank ledger. Accrued items that were never paid do
 * not touch those ledgers and therefore never appear.
 */
class ReceiptsPaymentsService
{
    public function __construct(private CashMovementReader $reader) {}

    /**
     * @param  ?Branch  $branch  Null for the consolidated view (needs `branches.view_all`).
     * @return array<string, mixed> `state` is `setup_required`, `no_cash_ledgers` or `ready`.
     */
    public function statement(User $actor, ?Branch $branch, string $from, string $to): array
    {
        $branchIds = $this->reader->authorizedBranchIds($actor, $branch);
        if (! Account::query()->exists()) {
            return ['state' => 'setup_required'];
        }
        if ($this->reader->cashLedgerIds() === []) {
            return ['state' => 'no_cash_ledgers'];
        }

        $movement = $this->reader->read($branchIds, $from, $to);
        $zero = BigDecimal::zero()->toScale(4);
        $accounts = Account::query()->whereIn('id', [...array_keys($movement['counter']), ...array_keys($movement['cash_accounts'])])
            ->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'account_type'])->keyBy('id');

        [$receipts, $payments, $totalReceipts, $totalPayments] = [[], [], $zero, $zero];
        foreach ($accounts as $account) {
            $amounts = $movement['counter'][$account->id] ?? null;
            if ($amounts === null) {
                continue;
            }
            if (! $amounts['inflows']->isZero()) {
                $receipts[] = ['account' => $account, 'amount' => AccountingDecimal::string($amounts['inflows'])];
                $totalReceipts = $totalReceipts->plus($amounts['inflows']);
            }
            if (! $amounts['outflows']->isZero()) {
                $payments[] = ['account' => $account, 'amount' => AccountingDecimal::string($amounts['outflows'])];
                $totalPayments = $totalPayments->plus($amounts['outflows']);
            }
        }

        $financialAccounts = FinancialAccount::query()->whereIn('account_id', array_keys($movement['cash_accounts']))->get(['account_id', 'name', 'type'])->keyBy('account_id');
        $cashAccounts = [];
        foreach ($accounts as $account) {
            $balances = $movement['cash_accounts'][$account->id] ?? null;
            if ($balances === null) {
                continue;
            }
            $financial = $financialAccounts->get($account->id);
            $cashAccounts[] = [
                'account' => $account, 'name' => $financial?->name ?? $account->name, 'type' => $financial?->type ?? 'petty cash',
                'opening' => AccountingDecimal::string($balances['opening']), 'closing' => AccountingDecimal::string($balances['closing']),
                'movement' => AccountingDecimal::string($balances['closing']->minus($balances['opening'])),
            ];
        }

        $expectedClosing = $movement['opening']->plus($totalReceipts)->minus($totalPayments);
        $difference = $movement['closing']->minus($expectedClosing);

        return [
            'state' => 'ready', 'from' => $from, 'to' => $to, 'receipts' => $receipts, 'payments' => $payments,
            'total_receipts' => AccountingDecimal::string($totalReceipts), 'total_payments' => AccountingDecimal::string($totalPayments),
            'net' => AccountingDecimal::string($totalReceipts->minus($totalPayments)),
            'opening' => AccountingDecimal::string($movement['opening']), 'expected_closing' => AccountingDecimal::string($expectedClosing),
            'closing' => AccountingDecimal::string($movement['closing']),
            'difference' => AccountingDecimal::string($difference), 'reconciled' => $difference->isZero(),
            'cash_accounts' => $cashAccounts, 'cash_movement' => AccountingDecimal::string($movement['closing']->minus($movement['opening'])),
            'transfers' => ['count' => $movement['transfers']['count'], 'amount' => AccountingDecimal::string($movement['transfers']['amount'])],
            'journal_count' => $movement['journal_count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  array<string, string>  $meta
     */
    public function exportTable(array $statement, array $meta): ReportTable
    {
        abort_unless(($statement['state'] ?? null) === 'ready', 409, 'The Receipts & Payments Statement is not available until accounting setup is complete.');

        $rows = [['item' => 'Opening cash and bank balance', 'type' => null, 'amount' => $statement['opening'], '_style' => 'total']];
        foreach (['receipts' => 'Receipts', 'payments' => 'Payments'] as $key => $label) {
            $rows[] = ['item' => $label, 'type' => null, 'amount' => null, '_style' => 'heading'];
            foreach ($statement[$key] as $row) {
                $rows[] = ['item' => $row['account']->code.' '.$row['account']->name, 'type' => ucfirst($row['account']->account_type->value), 'amount' => $row['amount'], '_indent' => 1];
            }
            $rows[] = ['item' => 'Total '.strtolower($label), 'type' => null, 'amount' => $statement['total_'.$key], '_style' => 'subtotal'];
        }
        $rows[] = ['item' => 'Closing cash and bank balance (opening + receipts - payments)', 'type' => null, 'amount' => $statement['expected_closing'], '_style' => 'total'];
        $rows[] = ['item' => 'General Ledger cash and bank balance at period end', 'type' => null, 'amount' => $statement['closing']];
        $rows[] = ['item' => 'Reconciliation difference', 'type' => null, 'amount' => $statement['difference']];
        $rows[] = ['item' => 'Cash and bank accounts', 'type' => null, 'amount' => null, '_style' => 'heading'];
        foreach ($statement['cash_accounts'] as $row) {
            $rows[] = ['item' => $row['account']->code.' '.$row['name'].' — closing balance', 'type' => ucfirst($row['type']), 'amount' => $row['closing'], '_indent' => 1];
        }

        return new ReportTable(
            'Receipts & Payments Statement',
            [['key' => 'item', 'label' => 'Item'], ['key' => 'type', 'label' => 'Account type'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            $rows,
            [...$meta, 'Basis' => 'Cash basis'],
            'Cash basis: actual cash and bank movement of posted journals only. Accrued but unpaid items are not included. A reversal reduces the side of its original. Transfers between cash and bank ledgers are excluded.',
        );
    }
}
