<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Receipt',
            self::Issue => 'Issue',
            self::Return => 'Return',
            self::Adjustment => 'Adjustment',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
        };
    }

    /** Audit event recorded when a movement of this type is written. */
    public function auditEvent(): string
    {
        return match ($this) {
            self::Receipt => 'inventory.received',
            self::Issue => 'inventory.issued',
            self::Return => 'inventory.returned',
            self::Adjustment => 'inventory.adjustment_requested',
            self::TransferOut => 'inventory.transferred_out',
            self::TransferIn => 'inventory.transferred_in',
        };
    }
}
