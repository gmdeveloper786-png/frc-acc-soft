<?php

namespace App\Enums;

enum FinancialStatement: string
{
    case BalanceSheet = 'balance_sheet';
    case IncomeExpenditure = 'income_expenditure';
}
