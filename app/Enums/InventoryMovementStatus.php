<?php

namespace App\Enums;

enum InventoryMovementStatus: string
{
    case Posted = 'posted';
    case Pending = 'pending';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Posted => 'success',
            self::Pending => 'pending',
            self::Rejected => 'danger',
        };
    }
}
