<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class AccountingDecimal
{
    public static function of(mixed $value): BigDecimal
    {
        try {
            return BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid financial amount.');
        }
    }

    public static function string(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::Unnecessary);
    }
}
