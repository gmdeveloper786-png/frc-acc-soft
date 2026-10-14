<?php

namespace App\Enums;

enum OperationalTransactionType: string
{
    case CashReceipt = 'cash_receipt';
    case BankReceipt = 'bank_receipt';
    case CashPayment = 'cash_payment';
    case BankPayment = 'bank_payment';
    case Expense = 'expense';
    case PettyCash = 'petty_cash';
    case Donation = 'donation';
    case VendorPayment = 'vendor_payment';

    public function permissionPrefix(): string
    {
        return match ($this) {
            self::CashReceipt, self::BankReceipt => 'receipts',
            self::CashPayment, self::BankPayment => 'payments',
            self::Expense => 'expenses',
            self::PettyCash => 'petty_cash',
            self::Donation => 'donations',
            self::VendorPayment => 'vendor_payments',
        };
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function voucherCode(): string
    {
        return match ($this) {
            self::CashReceipt => 'CR', self::BankReceipt => 'BR',
            self::CashPayment, self::PettyCash => 'CP', self::BankPayment, self::VendorPayment => 'BP',
            self::Expense => 'JV', self::Donation => 'DR',
        };
    }

    public function requiresApproval(): bool
    {
        return in_array($this, [self::CashPayment, self::BankPayment, self::Expense, self::PettyCash, self::VendorPayment], true);
    }

    public function requiresDisbursement(): bool
    {
        return in_array($this, [self::CashPayment, self::BankPayment, self::PettyCash, self::VendorPayment], true);
    }
}
