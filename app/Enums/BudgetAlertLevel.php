<?php

namespace App\Enums;

enum BudgetAlertLevel: string
{
    case Warning = 'warning';
    case Overrun = 'overrun';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return $this === self::Overrun ? 'danger' : 'pending';
    }
}
