<?php

namespace App\Enums;

/**
 * Where actual activity stands against a budget allocation. Informational
 * only: no status ever blocks a transaction.
 */
enum BudgetUtilisationStatus: string
{
    case Within = 'within';
    case Warning = 'warning';
    case Over = 'over';
    case Unbudgeted = 'unbudgeted';
    case IncomeTarget = 'income_target';

    public function label(): string
    {
        return match ($this) {
            self::Within => 'Within budget',
            self::Warning => 'Warning',
            self::Over => 'Over budget',
            self::Unbudgeted => 'Unbudgeted spend',
            self::IncomeTarget => 'Income target',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Within => 'success',
            self::Warning => 'pending',
            self::Over, self::Unbudgeted => 'danger',
            self::IncomeTarget => 'neutral',
        };
    }

    /** The alert level this status is recorded under, if any. */
    public function alertLevel(): ?BudgetAlertLevel
    {
        return match ($this) {
            self::Warning => BudgetAlertLevel::Warning,
            self::Over, self::Unbudgeted => BudgetAlertLevel::Overrun,
            default => null,
        };
    }
}
