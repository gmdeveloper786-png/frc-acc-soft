<?php

namespace App\Enums;

enum FixedAssetStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Retired = 'retired';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Retired => 'danger',
            self::Draft => 'pending',
            self::FullyDepreciated => 'neutral',
        };
    }

    /** Financial terms may only change before the asset is put into use. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
