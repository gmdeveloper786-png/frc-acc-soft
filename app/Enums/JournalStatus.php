<?php

namespace App\Enums;

enum JournalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case Reversed = 'reversed';

    /** Screen label; a rejected journal is returned to its maker for correction. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Rejected => 'Returned',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }
}
