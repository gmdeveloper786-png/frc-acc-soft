<?php

namespace App\Services\BankReconciliation;

use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact amount helpers for bank reconciliation. Statement amounts carry at
 * most two decimals; nothing here touches floating point.
 */
final class Amount
{
    /**
     * Parse an amount typed or stored in a statement file. Thousands
     * separators and spaces are ignored and "(1,250.00)" means negative.
     * Returns null when the text is not a plain amount with at most two decimals.
     */
    public static function parse(?string $text): ?BigDecimal
    {
        $clean = str_replace([',', ' ', "\u{00A0}"], '', trim((string) $text));
        $negative = false;
        if (preg_match('/^\((.+)\)$/', $clean, $parts) === 1) {
            $clean = $parts[1];
            $negative = true;
        }
        if (preg_match('/^[+-]?\d+(\.\d{1,2})?$/', $clean) !== 1 || ($negative && str_starts_with($clean, '-'))) {
            return null;
        }
        $value = AccountingDecimal::of(ltrim($clean, '+'));

        return $negative ? $value->negated() : $value;
    }

    /** Two-decimal display with thousands separators, e.g. "-1,250.00". */
    public static function format(BigDecimal|string|null $value): string
    {
        $rounded = (string) AccountingDecimal::of($value instanceof BigDecimal ? (string) $value : $value)->toScale(2, RoundingMode::HalfUp);
        [$whole, $fraction] = explode('.', ltrim($rounded, '-'));
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));

        return (str_starts_with($rounded, '-') ? '-' : '').$whole.'.'.$fraction;
    }

    /** Signed bank-side amount of a journal line: debit (deposit) positive, credit (payment) negative. */
    public static function journalLine(mixed $debit, mixed $credit): BigDecimal
    {
        return AccountingDecimal::of($debit)->minus(AccountingDecimal::of($credit));
    }
}
