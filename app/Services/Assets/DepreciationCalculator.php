<?php

namespace App\Services\Assets;

use App\Enums\DepreciationConvention;
use App\Models\FixedAsset;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Straight-line depreciation arithmetic. Pure calculation: no database
 * access, no status checks and no posting. Every amount is an exact
 * 4-decimal BigDecimal.
 */
class DepreciationCalculator
{
    /** Safety stop for projections; far beyond any realistic useful life. */
    private const MAX_PROJECTED_PERIODS = 1300;

    public function depreciableAmount(FixedAsset $asset): BigDecimal
    {
        return AccountingDecimal::of($asset->acquisition_cost)->minus(AccountingDecimal::of($asset->residual_value));
    }

    /** Depreciable amount spread evenly over the useful life, rounded half-up to 4 dp. */
    public function monthlyAmount(FixedAsset $asset): BigDecimal
    {
        return $this->depreciableAmount($asset)->dividedBy(max(1, (int) $asset->useful_life_months), 4, RoundingMode::HalfUp);
    }

    /** First day of the first month in which the asset depreciates. */
    public function firstDepreciationMonth(FixedAsset $asset, DepreciationConvention $convention): CarbonImmutable
    {
        $month = CarbonImmutable::parse($asset->in_service_date)->startOfMonth();

        return $convention === DepreciationConvention::FollowingMonth ? $month->addMonthNoOverflow() : $month;
    }

    /**
     * Depreciation for one period given what has already been accumulated, or
     * null when the asset earns none (not yet in service, or nothing left).
     * History is an input and is never recalculated: the last period simply
     * takes whatever remains, so the total equals the depreciable amount.
     */
    public function amountForPeriod(FixedAsset $asset, CarbonInterface $periodStart, CarbonInterface $periodEnd, BigDecimal $accumulatedBefore, DepreciationConvention $convention): ?BigDecimal
    {
        $remaining = $this->depreciableAmount($asset)->minus($accumulatedBefore);
        if (! $remaining->isPositive()) {
            return null;
        }

        $inService = CarbonImmutable::parse($asset->in_service_date)->startOfDay();
        $start = CarbonImmutable::parse($periodStart)->startOfDay();
        $end = CarbonImmutable::parse($periodEnd)->startOfDay();
        if ($convention === DepreciationConvention::FollowingMonth ? $start->lt($this->firstDepreciationMonth($asset, $convention)) : $end->lt($inService->startOfMonth())) {
            return null;
        }
        if ($convention === DepreciationConvention::DailyProRata && $end->lt($inService)) {
            return null;
        }

        $amount = $this->monthlyAmount($asset);
        if ($convention === DepreciationConvention::DailyProRata && $inService->gt($start)) {
            $daysInPeriod = (int) $start->diffInDays($end) + 1;
            $daysInService = (int) $inService->diffInDays($end) + 1;
            $amount = $amount->multipliedBy($daysInService)->dividedBy($daysInPeriod, 4, RoundingMode::HalfUp);
        }

        // Rounding the monthly amount can leave at most half a unit of the last
        // decimal place per month; that dust is absorbed by the final period.
        $roundingDust = BigDecimal::of('0.00005')->multipliedBy(max(1, (int) $asset->useful_life_months));
        if ($amount->isGreaterThanOrEqualTo($remaining) || $remaining->minus($amount)->isLessThanOrEqualTo($roundingDust)) {
            $amount = $remaining;
        }

        return $amount->isPositive() ? $amount->toScale(4, RoundingMode::Unnecessary) : null;
    }

    /**
     * Month-by-month projection from the given month until the asset is fully
     * depreciated, assuming a run is posted for every month. A projection
     * only: nothing here is posted or stored.
     *
     * @return list<array{period_start: CarbonImmutable, period_end: CarbonImmutable, amount: string, accumulated_after: string, net_book_value_after: string}>
     */
    public function projection(FixedAsset $asset, BigDecimal $accumulated, CarbonInterface $fromMonth, DepreciationConvention $convention): array
    {
        $rows = [];
        $cost = AccountingDecimal::of($asset->acquisition_cost);
        $month = CarbonImmutable::parse($fromMonth)->startOfMonth();
        $first = $this->firstDepreciationMonth($asset, $convention);
        if ($month->lt($first)) {
            $month = $first;
        }

        for ($index = 0; $index < self::MAX_PROJECTED_PERIODS; $index++) {
            $amount = $this->amountForPeriod($asset, $month, $month->endOfMonth(), $accumulated, $convention);
            if ($amount === null) {
                break;
            }
            $accumulated = $accumulated->plus($amount);
            $rows[] = [
                'period_start' => $month, 'period_end' => $month->endOfMonth()->startOfDay(),
                'amount' => AccountingDecimal::string($amount), 'accumulated_after' => AccountingDecimal::string($accumulated),
                'net_book_value_after' => AccountingDecimal::string($cost->minus($accumulated)),
            ];
            $month = $month->addMonthNoOverflow();
        }

        return $rows;
    }
}
