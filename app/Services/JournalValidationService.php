<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\FiscalYearStatus;
use App\Models\JournalEntry;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

class JournalValidationService
{
    /** @return array{total_debit:string,total_credit:string} */
    public function validate(JournalEntry $journal): array
    {
        $journal->loadMissing(['lines.account', 'lines.service', 'fiscalYear', 'accountingPeriod', 'branch']);
        $errors = [];
        $debit = BigDecimal::zero()->toScale(4);
        $credit = BigDecimal::zero()->toScale(4);

        if ($journal->lines->count() < 2) {
            $errors['lines'] = 'A journal requires at least two lines.';
        }

        foreach ($journal->lines as $index => $line) {
            try {
                $lineDebit = AccountingDecimal::of($line->debit);
                $lineCredit = AccountingDecimal::of($line->credit);
            } catch (\InvalidArgumentException) {
                $errors["lines.{$index}"] = 'The line contains an invalid amount.';

                continue;
            }

            if ($lineDebit->isNegative() || $lineCredit->isNegative()) {
                $errors["lines.{$index}"] = 'Journal amounts cannot be negative.';
            } elseif ((! $lineDebit->isZero() && ! $lineCredit->isZero()) || ($lineDebit->isZero() && $lineCredit->isZero())) {
                $errors["lines.{$index}"] = 'Enter a positive debit or credit, but not both.';
            }

            $account = $line->account;
            if (! $account || ! $account->is_active || ! $account->is_postable || $account->level !== AccountLevel::Ledger) {
                $errors["lines.{$index}.account_id"] = 'Only active, postable ledger accounts may receive journal activity.';
            }
            if ($line->service_id && (! $line->service || ! in_array($line->service->status, ['publish', 'active'], true))) {
                $errors["lines.{$index}.service_id"] = 'The selected service is not available.';
            }

            $debit = $debit->plus($lineDebit);
            $credit = $credit->plus($lineCredit);
        }

        if ($debit->isZero() || $credit->isZero() || ! $debit->isEqualTo($credit)) {
            $errors['lines'] = 'Journal debit and credit totals must be positive and exactly equal.';
        }

        $date = $journal->transaction_date?->toDateString();
        if (! $date || $date < $journal->fiscalYear->start_date->toDateString() || $date > $journal->fiscalYear->end_date->toDateString()) {
            $errors['transaction_date'] = 'Transaction date must fall inside the selected fiscal year.';
        }
        if ($journal->accountingPeriod->fiscal_year_id !== $journal->fiscal_year_id
            || ! $date
            || $date < $journal->accountingPeriod->start_date->toDateString()
            || $date > $journal->accountingPeriod->end_date->toDateString()) {
            $errors['accounting_period_id'] = 'Accounting period must match the fiscal year and transaction date.';
        }
        if ($journal->fiscalYear->status !== FiscalYearStatus::Open) {
            $errors['fiscal_year_id'] = 'The fiscal year is not open for posting.';
        }
        if ($journal->accountingPeriod->status !== AccountingPeriodStatus::Open) {
            $errors['accounting_period_id'] = 'The accounting period is not open for posting.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['total_debit' => AccountingDecimal::string($debit), 'total_credit' => AccountingDecimal::string($credit)];
    }
}
