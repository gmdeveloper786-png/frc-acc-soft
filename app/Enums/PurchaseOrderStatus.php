<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Issued = 'issued';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Approved, self::Issued => 'success',
            self::Rejected, self::Cancelled => 'danger',
            self::Submitted => 'pending',
            self::Draft, self::Closed => 'neutral',
        };
    }

    /** Header and lines may only change before the order enters approval. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** Purchase bills may only reference an order that has been approved. */
    public function acceptsBills(): bool
    {
        return in_array($this, [self::Approved, self::Issued], true);
    }
}
