<?php

namespace App\Rules;

use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount no larger than the largest figure a posted journal can hold.
 * Compared as an exact decimal, never as a float; malformed values are left
 * to the decimal rule that accompanies it.
 */
class WithinFinancialMaximum implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return;
        }
        try {
            $amount = BigDecimal::of(trim((string) $value));
        } catch (\Throwable) {
            return;
        }
        if (AccountingDecimal::exceedsMaximum($amount)) {
            $fail(AccountingDecimal::maximumMessage());
        }
    }
}
