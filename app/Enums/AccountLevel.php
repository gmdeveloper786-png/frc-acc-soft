<?php

namespace App\Enums;

enum AccountLevel: string
{
    case ClassLevel = 'class';
    case Group = 'group';
    case SubGroup = 'sub_group';
    case Ledger = 'ledger';

    public function parentLevel(): ?self
    {
        return match ($this) {
            self::ClassLevel => null,
            self::Group => self::ClassLevel,
            self::SubGroup => self::Group,
            self::Ledger => self::SubGroup,
        };
    }
}
