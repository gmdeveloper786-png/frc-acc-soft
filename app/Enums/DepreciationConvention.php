<?php

namespace App\Enums;

enum DepreciationConvention: string
{
    case FullMonth = 'full_month';
    case FollowingMonth = 'following_month';
    case DailyProRata = 'daily_pro_rata';

    public function label(): string
    {
        return match ($this) {
            self::FullMonth => 'Full month from the in-service month',
            self::FollowingMonth => 'Start in the month after in-service',
            self::DailyProRata => 'Pro-rata by days in the first and last month',
        };
    }
}
