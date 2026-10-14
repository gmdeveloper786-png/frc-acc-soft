<?php

namespace App\Enums;

/**
 * Organisation-level control accounts that are not already mapped elsewhere.
 * Cash/bank ledgers live on financial accounts, donation income on donation
 * categories and service fee income on FRC services.
 */
enum AccountMappingKey: string
{
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable = 'accounts_payable';
    case PurchaseBillControl = 'purchase_bill_control';
    case FeeIncomeGeneral = 'fee_income_general';
    case DefaultExpense = 'default_expense';
    case PettyCash = 'petty_cash';
    case SalaryExpense = 'salary_expense';
    case SalaryPayable = 'salary_payable';

    public function label(): string
    {
        return match ($this) {
            self::AccountsReceivable => 'Accounts Receivable',
            self::AccountsPayable => 'Accounts Payable',
            self::PurchaseBillControl => 'Purchase Bill control account',
            self::FeeIncomeGeneral => 'General Fee Income',
            self::DefaultExpense => 'Default Expense',
            self::PettyCash => 'Petty Cash',
            self::SalaryExpense => 'Salary Expense',
            self::SalaryPayable => 'Salary Payable',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AccountsReceivable => 'Control ledger for client service fees receivable.',
            self::AccountsPayable => 'Default payable ledger for purchase bills and accrued expenses.',
            self::PurchaseBillControl => 'Optional separate payable ledger for purchase bills. Accounts Payable is used when this is not mapped.',
            self::FeeIncomeGeneral => 'Fee income ledger used when a service has no ledger of its own.',
            self::DefaultExpense => 'Optional expense ledger used when a payment or expense has no ledger selected.',
            self::PettyCash => 'Optional petty cash control ledger.',
            self::SalaryExpense => 'Expense ledger debited when an approved payroll run is accrued. Required before payroll posting.',
            self::SalaryPayable => 'Liability ledger credited with net salaries and cleared by salary payments. Required before payroll posting.',
        };
    }

    public function accountType(): AccountType
    {
        return match ($this) {
            self::AccountsReceivable, self::PettyCash => AccountType::Asset,
            self::AccountsPayable, self::PurchaseBillControl, self::SalaryPayable => AccountType::Liability,
            self::FeeIncomeGeneral => AccountType::Income,
            self::DefaultExpense, self::SalaryExpense => AccountType::Expense,
        };
    }

    /** A missing required mapping blocks the postings that depend on it. */
    public function isRequired(): bool
    {
        return in_array($this, [self::AccountsReceivable, self::AccountsPayable, self::FeeIncomeGeneral], true);
    }
}
