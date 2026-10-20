<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class AccountingDecimal
{
    /**
     * The largest amount a journal line, or a journal debit or credit total,
     * can hold: journal_lines and journal_entries store amounts as
     * DECIMAL(19,4). Every amount that is posted ends up there, so this is the
     * ceiling for opening balances too (whose own columns are DECIMAL(20,2)).
     */
    public const MAX_AMOUNT = '999999999999999.9999';

    /** Exact comparison with the ceiling; never rounds or converts to float. */
    public static function exceedsMaximum(BigDecimal|string $value): bool
    {
        return BigDecimal::of((string) $value)->isGreaterThan(BigDecimal::of(self::MAX_AMOUNT));
    }

    public static function maximumMessage(string $what = 'Amounts'): string
    {
        return $what.' cannot exceed 999,999,999,999,999.9999, the largest figure a journal can hold.';
    }

    public static function of(mixed $value): BigDecimal
    {
        try {
            return BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid financial amount.');
        }
    }

    /**
     * Display an amount with thousands separators at the given display
     * precision, from its exact decimal value: never through a float, so a
     * large amount is never shown as a different number. Display precision is
     * independent of storage precision (four places): an amount stored as
     * 150000.0100 shows as 150,000.01 at two places. Rounding to the display
     * precision is half-up, as number_format did; a value that rounds to zero
     * shows without a minus sign. Text that is not a number is shown as is.
     */
    public static function format(mixed $value, int $scale = 2, ?int $maxScale = null): string
    {
        try {
            $text = $value instanceof BigDecimal ? (string) $value : trim((string) ($value ?? ''));
            $amount = BigDecimal::of($text === '' ? '0' : $text);
            // With $maxScale, show at least $scale places and as many more of the
            // value's own non-zero places as exist, up to $maxScale, so a
            // four-place journal amount is never rounded for display.
            if ($maxScale !== null) {
                $digits = (string) $amount->getUnscaledValue();
                $own = $amount->isZero() ? 0 : $amount->getScale() - (strlen($digits) - strlen(rtrim($digits, '0')));
                $scale = max($scale, min($maxScale, $own));
            }
            $rounded = $amount->toScale($scale, RoundingMode::HalfUp);
        } catch (\Throwable) {
            return (string) $value;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $rounded->abs(), 2), 2, '');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($rounded->isNegative() ? '-' : '').$grouped.($scale > 0 ? '.'.$fraction : '');
    }

    public static function string(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::Unnecessary);
    }
}
