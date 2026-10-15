<?php

namespace App\Enums;

enum StaffAdvanceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Active = 'active';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Approved, self::Active => 'success',
            self::Cancelled => 'danger',
            self::Submitted => 'pending',
            self::Draft, self::Settled => 'neutral',
        };
    }

    /** Amounts, schedule and recovery component may only change while a draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Paid out: the schedule is recovered from posted payroll runs. */
    public function isDisbursed(): bool
    {
        return in_array($this, [self::Active, self::Settled], true);
    }
}
