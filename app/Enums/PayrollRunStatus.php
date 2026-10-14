<?php

namespace App\Enums;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Approved, self::Posted, self::Paid => 'success',
            self::Rejected, self::Reversed => 'danger',
            self::Validated, self::Submitted => 'pending',
            self::Draft => 'neutral',
        };
    }

    /** Lines and amounts may only change before the run is validated. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** A rejected or reversed run no longer blocks its branch and period. */
    public function occupiesPeriod(): bool
    {
        return ! in_array($this, [self::Rejected, self::Reversed], true);
    }

    /** The accrual journal exists: nothing but payment or reversal may follow. */
    public function isPosted(): bool
    {
        return in_array($this, [self::Posted, self::Paid, self::Reversed], true);
    }
}
