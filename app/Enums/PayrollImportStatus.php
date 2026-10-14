<?php

namespace App\Enums;

enum PayrollImportStatus: string
{
    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Validated = 'validated';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Cancelled => 'danger',
            self::Validated, self::Mapped => 'pending',
            self::Uploaded => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Uploaded, self::Mapped, self::Validated], true);
    }
}
