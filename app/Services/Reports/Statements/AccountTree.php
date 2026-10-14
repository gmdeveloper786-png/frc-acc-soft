<?php

namespace App\Services\Reports\Statements;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Models\Account;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Turns ledger movements into a class → group → sub-group → ledger hierarchy.
 * Every posting is counted once, on the account it was posted to; parents are
 * pure rollups of their children.
 */
class AccountTree
{
    /**
     * @param  Collection<int, Account>  $accounts  Every account of the chart, in display order.
     * @param  array<int, array<string, BigDecimal>>  $movements  Net debit-minus-credit by account id and column key.
     *                                                            An account is listed only when it has an entry here.
     * @param  list<string>  $columns
     * @return array{rows: list<array{id: int, code: string, name: string, level: string, depth: int, is_ledger: bool, amounts: array<string, string>}>, totals: array<string, string>}
     */
    public function build(Collection $accounts, AccountType $type, array $movements, array $columns): array
    {
        $accounts = $accounts->filter(fn (Account $account): bool => $account->account_type === $type)->values();
        $ids = $accounts->pluck('id')->flip();
        // Income, liabilities and equity are credit-natured, so their sign is flipped for presentation.
        $creditNatured = in_array($type, [AccountType::Income, AccountType::Liability, AccountType::Equity], true);
        $zero = BigDecimal::zero()->toScale(4);

        $own = [];
        $totals = array_fill_keys($columns, $zero);
        foreach ($accounts as $account) {
            if (! isset($movements[$account->id])) {
                continue;
            }
            foreach ($columns as $column) {
                $value = $movements[$account->id][$column] ?? $zero;
                $own[$account->id][$column] = $creditNatured ? $value->negated() : $value;
                $totals[$column] = $totals[$column]->plus($own[$account->id][$column]);
            }
        }

        $children = $accounts->groupBy(fn (Account $account): int => $account->parent_id !== null && $ids->has($account->parent_id) ? (int) $account->parent_id : 0);
        $rows = [];
        $visited = [];
        $walk = function (Account $account, int $depth) use (&$walk, &$rows, &$visited, $children, $own, $columns, $zero): ?array {
            if (isset($visited[$account->id])) {
                return null;
            }
            $visited[$account->id] = true;
            $index = count($rows);
            $rows[] = null;
            $amounts = $own[$account->id] ?? array_fill_keys($columns, $zero);
            $active = isset($own[$account->id]);
            foreach ($children->get($account->id, []) as $child) {
                $childAmounts = $walk($child, $depth + 1);
                if ($childAmounts !== null) {
                    $active = true;
                    foreach ($columns as $column) {
                        $amounts[$column] = $amounts[$column]->plus($childAmounts[$column]);
                    }
                }
            }
            if (! $active) {
                // No posted activity below this account: drop the placeholder row.
                array_splice($rows, $index, 1);

                return null;
            }
            $rows[$index] = [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'level' => $account->level->value,
                'depth' => $depth, 'is_ledger' => $account->level === AccountLevel::Ledger,
                'amounts' => array_map(fn (BigDecimal $value): string => AccountingDecimal::string($value), $amounts),
            ];

            return $amounts;
        };

        foreach ($children->get(0, []) as $root) {
            $walk($root, 0);
        }
        // Accounts unreachable from a root (a broken parent chain) are still listed so nothing is hidden.
        foreach ($accounts as $account) {
            if (isset($own[$account->id]) && ! isset($visited[$account->id])) {
                $walk($account, 0);
            }
        }

        return ['rows' => array_values($rows), 'totals' => array_map(fn (BigDecimal $value): string => AccountingDecimal::string($value), $totals)];
    }

    /**
     * Export rows for a built hierarchy: parents are bold rollups, ledgers are indented beneath them.
     *
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, string>}  $tree
     * @return list<array<string, mixed>>
     */
    public static function exportRows(array $tree, int $baseIndent = 1): array
    {
        return array_map(fn (array $row): array => [
            'account' => $row['code'].' '.$row['name'],
            ...$row['amounts'],
            '_indent' => $baseIndent + $row['depth'],
            ...($row['is_ledger'] ? [] : ['_style' => 'subtotal']),
        ], $tree['rows']);
    }
}
