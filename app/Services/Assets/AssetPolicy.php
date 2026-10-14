<?php

namespace App\Services\Assets;

use App\Enums\DepreciationConvention;
use App\Services\AccountingDecimal;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Read-only view of the Director-controlled fixed asset policy settings.
 */
class AssetPolicy
{
    public const APPROVAL_SETTING = 'assets.depreciation_policy_approved';

    public function __construct(private AppSettings $settings) {}

    /** Depreciation may only be posted once the policy has been approved. */
    public function isApproved(): bool
    {
        return $this->settings->integer(self::APPROVAL_SETTING, 0) === 1;
    }

    public function convention(): DepreciationConvention
    {
        return DepreciationConvention::tryFrom($this->settings->string('assets.depreciation_convention', DepreciationConvention::FullMonth->value)) ?? DepreciationConvention::FullMonth;
    }

    /** Zero means no capitalization threshold applies. */
    public function capitalizationThreshold(): BigDecimal
    {
        try {
            return BigDecimal::of($this->settings->string('assets.capitalization_threshold', '0') ?: '0')->toScale(4, RoundingMode::HalfUp);
        } catch (\Throwable) {
            return AccountingDecimal::of('0');
        }
    }
}
