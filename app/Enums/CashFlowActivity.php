<?php

namespace App\Enums;

enum CashFlowActivity: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating activities',
            self::Investing => 'Investing activities',
            self::Financing => 'Financing activities',
        };
    }
}
