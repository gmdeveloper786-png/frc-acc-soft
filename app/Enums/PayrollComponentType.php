<?php

namespace App\Enums;

enum PayrollComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
