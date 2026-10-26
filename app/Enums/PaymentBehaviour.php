<?php

namespace App\Enums;

/**
 * The fixed accounting behaviours a payment purpose may use. Each behaviour is
 * an existing, approved workflow with its own debit side and controls; a
 * purpose only selects one of them, so configuration can never invent GL
 * postings. Phase 2 may add configurable purposes, never new behaviours here.
 */
enum PaymentBehaviour: string
{
    case VendorBill = 'vendor_bill';
    case AccruedPayable = 'accrued_payable';
    case SalaryPayable = 'salary_payable';
    case StaffAdvance = 'staff_advance';
    case DirectExpense = 'direct_expense';

    public function label(): string
    {
        return match ($this) {
            self::VendorBill => 'Vendor bill payment',
            self::AccruedPayable => 'Accrued payable settlement',
            self::SalaryPayable => 'Salary payment (payroll run)',
            self::StaffAdvance => 'Staff advance / loan disbursement',
            self::DirectExpense => 'Direct expense',
        };
    }

    /**
     * Permission needed to raise this kind of payment from this source; the same one
     * the existing workflow requires. A petty cash payment is a petty cash voucher.
     */
    public function permission(string $source = 'bank'): string
    {
        return match (true) {
            $source === 'petty_cash' => 'petty_cash.create',
            $this === self::VendorBill => 'vendor_payments.create',
            $this === self::SalaryPayable => 'payroll.pay',
            default => 'payments.create',
        };
    }

    /**
     * Payee sources (PayeeType::SOURCE_*) a payment of this behaviour may name.
     * The payee is then checked against the supporting record on the server.
     *
     * @return list<string>
     */
    public function payeeSources(): array
    {
        return match ($this) {
            self::VendorBill => ['vendor'],
            self::AccruedPayable => ['vendor', 'directory'],
            self::SalaryPayable, self::StaffAdvance => ['employee'],
            self::DirectExpense => ['vendor', 'employee', 'directory'],
        };
    }

    /** A salary payment covers every employee of its payroll run, so it names no single payee. */
    public function requiresPayee(): bool
    {
        return $this !== self::SalaryPayable;
    }

    /** Only a direct expense charges a configurable ledger; every other behaviour takes its debit from its record. */
    public function usesDebitLedger(): bool
    {
        return $this === self::DirectExpense;
    }

    /**
     * Payment sources this behaviour supports. Only a direct expense may be paid
     * as a petty cash voucher; settlements of bills, payables, salary and advances
     * are made by bank or cash payment, as their workflows require.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this === self::DirectExpense ? ['bank', 'cash', 'petty_cash'] : ['bank', 'cash'];
    }
}
