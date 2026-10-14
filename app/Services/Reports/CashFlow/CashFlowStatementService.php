<?php

namespace App\Services\Reports\CashFlow;

use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CashFlowClassification;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Reports\ReportTable;
use Brick\Math\BigDecimal;

/**
 * Cash Flow Statement by the DIRECT method: actual cash and bank movement of
 * posted journals, grouped by the activity explicitly configured for each
 * counter ledger. Nothing is inferred from account names or types, and the
 * statement is never forced to agree with the General Ledger.
 */
class CashFlowStatementService
{
    public const METHOD = 'Direct method';

    public const NOT_FINAL = 'Not final: classification incomplete';

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
        $counterIds = array_keys($movement['counter']);
        $accounts = Account::query()->whereIn('id', $counterIds)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'account_type']);
        $activities = CashFlowClassification::query()->whereIn('account_id', $counterIds)->pluck('activity', 'account_id');
        $zero = BigDecimal::zero()->toScale(4);

        $sections = [];
        foreach (CashFlowActivity::cases() as $activity) {
            $sections[$activity->value] = ['activity' => $activity, 'rows' => [], 'inflows' => $zero, 'outflows' => $zero];
        }
        $unclassified = ['rows' => [], 'inflows' => $zero, 'outflows' => $zero];
        foreach ($accounts as $account) {
            $amounts = $movement['counter'][$account->id];
            $row = [
                'account' => $account, 'inflows' => AccountingDecimal::string($amounts['inflows']), 'outflows' => AccountingDecimal::string($amounts['outflows']),
                'net' => AccountingDecimal::string($amounts['inflows']->minus($amounts['outflows'])),
            ];
            $activity = $activities->get($account->id);
            if ($activity instanceof CashFlowActivity) {
                $sections[$activity->value]['rows'][] = $row;
                $sections[$activity->value]['inflows'] = $sections[$activity->value]['inflows']->plus($amounts['inflows']);
                $sections[$activity->value]['outflows'] = $sections[$activity->value]['outflows']->plus($amounts['outflows']);
            } else {
                $unclassified['rows'][] = $row;
                $unclassified['inflows'] = $unclassified['inflows']->plus($amounts['inflows']);
                $unclassified['outflows'] = $unclassified['outflows']->plus($amounts['outflows']);
            }
        }
        $finish = fn (array $section): array => [
            ...$section, 'inflows' => AccountingDecimal::string($section['inflows']), 'outflows' => AccountingDecimal::string($section['outflows']),
            'net' => AccountingDecimal::string($section['inflows']->minus($section['outflows'])),
        ];

        $expectedClosing = $movement['opening']->plus($movement['net_change']);
        $difference = $movement['closing']->minus($expectedClosing);
        $isFinal = $unclassified['rows'] === [];
        $anyClassification = CashFlowClassification::query()->exists();

        return [
            'state' => 'ready', 'method' => self::METHOD, 'from' => $from, 'to' => $to,
            'sections' => array_map($finish, $sections), 'unclassified' => $finish($unclassified),
            'classification_state' => ! $anyClassification ? 'none' : ($isFinal ? 'complete' : 'incomplete'),
            'is_final' => $isFinal && $anyClassification,
            'net_change' => AccountingDecimal::string($movement['net_change']),
            'opening' => AccountingDecimal::string($movement['opening']),
            'expected_closing' => AccountingDecimal::string($expectedClosing),
            'closing' => AccountingDecimal::string($movement['closing']),
            'difference' => AccountingDecimal::string($difference), 'reconciled' => $difference->isZero(),
            'transfers' => ['count' => $movement['transfers']['count'], 'amount' => AccountingDecimal::string($movement['transfers']['amount'])],
            'journal_count' => $movement['journal_count'],
        ];
    }

    /**
     * The statement as an export table. Only a reconciled statement may be exported.
     *
     * @param  array<string, mixed>  $statement
     * @param  array<string, string>  $meta
     */
    public function exportTable(array $statement, array $meta): ReportTable
    {
        abort_unless(($statement['state'] ?? null) === 'ready' && $statement['reconciled'], 409, 'The Cash Flow Statement does not reconcile to the General Ledger cash balance and cannot be exported.');

        $rows = [];
        $sections = [...array_values($statement['sections']), ['label' => 'Unclassified — configuration required', ...$statement['unclassified']]];
        foreach ($sections as $section) {
            $label = $section['label'] ?? $section['activity']->label();
            if ($section['rows'] === [] && isset($section['label'])) {
                continue;
            }
            $rows[] = ['item' => $label, 'inflows' => null, 'outflows' => null, 'net' => null, '_style' => 'heading'];
            foreach ($section['rows'] as $row) {
                $rows[] = ['item' => $row['account']->code.' '.$row['account']->name, 'inflows' => $row['inflows'], 'outflows' => $row['outflows'], 'net' => $row['net'], '_indent' => 1];
            }
            $rows[] = ['item' => isset($section['label']) ? 'Net unclassified cash movement' : 'Net cash from '.lcfirst($label), 'inflows' => $section['inflows'], 'outflows' => $section['outflows'], 'net' => $section['net'], '_style' => 'subtotal'];
        }
        $rows[] = ['item' => 'Net change in cash and bank', 'inflows' => null, 'outflows' => null, 'net' => $statement['net_change'], '_style' => 'total'];
        $rows[] = ['item' => 'Opening cash and bank balance', 'inflows' => null, 'outflows' => null, 'net' => $statement['opening']];
        $rows[] = ['item' => 'Closing cash and bank balance (opening + net change)', 'inflows' => null, 'outflows' => null, 'net' => $statement['expected_closing'], '_style' => 'total'];
        $rows[] = ['item' => 'General Ledger cash and bank balance at period end', 'inflows' => null, 'outflows' => null, 'net' => $statement['closing']];
        $rows[] = ['item' => 'Reconciliation difference', 'inflows' => null, 'outflows' => null, 'net' => $statement['difference']];

        $note = 'Direct method. Posted journals only; a reversal reduces the side of its original. Transfers between cash and bank ledgers are excluded. Activities come from the configured ledger classification.';

        return new ReportTable(
            'Cash Flow Statement ('.self::METHOD.')',
            [['key' => 'item', 'label' => 'Item'], ['key' => 'inflows', 'label' => 'Inflows', 'type' => 'money'], ['key' => 'outflows', 'label' => 'Outflows', 'type' => 'money'], ['key' => 'net', 'label' => 'Net', 'type' => 'money']],
            $rows,
            [...$meta, 'Method' => self::METHOD, 'Status' => $statement['is_final'] ? 'Reconciled to the General Ledger' : self::NOT_FINAL],
            $statement['is_final'] ? $note : self::NOT_FINAL.'. '.$note,
        );
    }
}
