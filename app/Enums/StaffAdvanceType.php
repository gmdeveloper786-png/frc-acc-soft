<?php

namespace App\Enums;

enum StaffAdvanceType: string
{
    case Advance = 'advance';
    case Loan = 'loan';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Prefix of the generated reference number. */
    public function referencePrefix(): string
    {
        return match ($this) {
            self::Advance => 'ADV',
            self::Loan => 'LOAN',
        };
    }
}
