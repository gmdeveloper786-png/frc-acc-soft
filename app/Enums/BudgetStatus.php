<?php

namespace App\Enums;

enum BudgetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Submitted => 'pending',
            self::Draft, self::Superseded => 'neutral',
        };
    }

    /** Header and lines may only change before the budget enters approval. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** A version that is still on its way to approval. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Rejected], true);
    }
}
