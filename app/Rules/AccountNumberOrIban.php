<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccountNumberOrIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid domestic account number or IBAN.');

            return;
        }

        $formatted = strtoupper(trim($value));
        $compact = preg_replace('/\s+/', '', $formatted);

        if (preg_match('/^[A-Z]{2}\d{2}/', $compact) === 1) {
            if (! $this->isValidIban($compact)) {
                $fail('Enter a valid IBAN with a correct format and checksum.');
            }

            return;
        }

        $domestic = preg_replace('/[\s-]+/', '', $formatted);
        if (preg_match('/^[A-Z0-9]{6,34}$/', $domestic) !== 1 || preg_match('/\d/', $domestic) !== 1) {
            $fail('Enter a valid domestic account number or IBAN.');
        }
    }

    private function isValidIban(string $iban): bool
    {
        $length = strlen($iban);
        if ($length < 15 || $length > 34 || preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban) !== 1) {
            return false;
        }
        if (str_starts_with($iban, 'PK') && preg_match('/^PK\d{2}[A-Z]{4}[A-Z0-9]{16}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = (($remainder * 10) + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
