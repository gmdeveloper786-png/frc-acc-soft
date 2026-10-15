<?php

namespace App\Services\Funds;

use App\Models\Fund;
use Illuminate\Database\Eloquent\Collection;

/**
 * The fund filter of the fund reports. Choosing a parent fund (e.g. GRT)
 * also covers its sub-funds (GRT-01, GRT-02 ...).
 */
final class FundFilter
{
    /** @return Collection<int, Fund>|null Null when no fund filter is applied. */
    public static function funds(mixed $fundId): ?Collection
    {
        if (blank($fundId)) {
            return null;
        }
        $fund = Fund::query()->findOrFail((int) $fundId);

        return Fund::query()->whereKey($fund->id)->orWhere('parent_id', $fund->id)->orderBy('code')->get();
    }
}
