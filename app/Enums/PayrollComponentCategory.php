<?php

namespace App\Enums;

/**
 * Descriptive grouping only. No category carries a calculation rule: every
 * amount is supplied by an authorised user or by a reviewed import.
 */
enum PayrollComponentCategory: string
{
    case BaseSalary = 'base_salary';
    case Allowance = 'allowance';
    case Bonus = 'bonus';
    case Arrears = 'arrears';
    case Advance = 'advance';
    case Tax = 'tax';
    case LeaveAdjustment = 'leave_adjustment';
    case Statutory = 'statutory';
    case OtherDeduction = 'other_deduction';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
