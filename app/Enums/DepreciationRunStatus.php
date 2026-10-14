<?php

namespace App\Enums;

enum DepreciationRunStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Posted => 'success',
            self::Reversed => 'danger',
            self::Submitted, self::Approved => 'pending',
            self::Draft => 'neutral',
        };
    }
}
