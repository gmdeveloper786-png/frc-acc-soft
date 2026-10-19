<?php

namespace App\Services;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialStatement;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Support\AppSettings;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class OpeningBalanceValidationService
{
    private const ELIGIBLE_ACCOUNT_TYPES = [AccountType::Asset, AccountType::Liability, AccountType::Equity];

    public function __construct(private AppSettings $settings) {}

    /** @return Builder<Account> */
    public function eligibleAccounts(): Builder
    {
        return Account::query()
            ->where('level', AccountLevel::Ledger->value)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->where('financial_statement', FinancialStatement::BalanceSheet->value)
            ->whereIn('account_type', array_map(fn (AccountType $type): string => $type->value, self::ELIGIBLE_ACCOUNT_TYPES));
    }

    public function isEligibleAccount(?Account $account): bool
    {
        return $account !== null
            && $account->level === AccountLevel::Ledger
            && $account->is_active
            && $account->is_postable
            && $account->financial_statement === FinancialStatement::BalanceSheet
            && in_array($account->account_type, self::ELIGIBLE_ACCOUNT_TYPES, true);
    }

    public function assertEligibleAccount(?Account $account, string $field): void
    {
        if (! $this->isEligibleAccount($account)) {
            throw ValidationException::withMessages([
                $field => 'Opening balances may use only active, postable Balance Sheet ledger accounts classified as Asset, Liability or Equity.',
            ]);
        }
    }

    public function assertOpeningDate(FiscalYear $year, string $openingDate): void
    {
        $yearStart = $year->start_date->toDateString();
        $configuredDate = $this->settings->string('fiscal.initial_opening_date', (string) config('accounting.initial_opening_date'));

        if ($configuredDate !== $yearStart) {
            throw ValidationException::withMessages([
                'opening_date' => "The configured initial opening date ({$configuredDate}) conflicts with {$year->name}'s start date ({$yearStart}). Update Accounting Settings before preparing this opening balance.",
            ]);
        }

        if ($openingDate !== $yearStart) {
            throw ValidationException::withMessages([
                'opening_date' => "Opening date must equal the selected fiscal year's start date ({$yearStart}).",
            ]);
        }
    }

    public function assertBatchIsValid(OpeningBalanceBatch $batch): void
    {
        $batch->loadMissing(['fiscalYear', 'lines.account']);
        $this->assertOpeningDate($batch->fiscalYear, $batch->opening_date->toDateString());

        $errors = [];
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        $seen = [];

        if ($batch->lines->count() < 2) {
            $errors['lines'] = 'Opening balances require at least two valid lines.';
        }

        foreach ($batch->lines as $index => $line) {
            if (! $this->isEligibleAccount($line->account)) {
                $errors["lines.{$index}.account_id"] = 'Opening balances may use only active, postable Balance Sheet ledger accounts classified as Asset, Liability or Equity.';
            }
            if (isset($seen[$line->account_id])) {
                $errors["lines.{$index}.account_id"] = 'Each account may appear only once in an opening balance.';
            }
            $seen[$line->account_id] = true;

            $lineDebit = BigDecimal::of($line->debit);
            $lineCredit = BigDecimal::of($line->credit);
            if ($lineDebit->isNegative() || $lineCredit->isNegative()) {
                $errors["lines.{$index}"] = 'Opening amounts cannot be negative.';
            } elseif ((! $lineDebit->isZero() && ! $lineCredit->isZero()) || ($lineDebit->isZero() && $lineCredit->isZero())) {
                $errors["lines.{$index}"] = 'Enter a positive debit or credit, but not both.';
            }

            $debit = $debit->plus($lineDebit);
            $credit = $credit->plus($lineCredit);
        }

        if ($debit->isZero() || $credit->isZero() || ! $debit->isEqualTo($credit)) {
            $errors['lines'] = 'Opening balance debit and credit totals must be positive and exactly equal.';
        }
        if (! $debit->isEqualTo(BigDecimal::of($batch->total_debit))
            || ! $credit->isEqualTo(BigDecimal::of($batch->total_credit))
            || ! BigDecimal::of($batch->difference)->isZero()) {
            $errors['totals'] = 'Stored opening balance totals do not match the validated lines.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, string> */
    public function journalErrors(JournalEntry $journal): array
    {
        if ($journal->source_type !== 'opening_balance') {
            return [];
        }

        $journal->loadMissing(['fiscalYear', 'lines.account']);
        $errors = [];
        $yearStart = $journal->fiscalYear->start_date->toDateString();
        $configuredDate = $this->settings->string('fiscal.initial_opening_date', (string) config('accounting.initial_opening_date'));

        if ($configuredDate !== $yearStart || $journal->transaction_date?->toDateString() !== $yearStart) {
            $errors['transaction_date'] = 'Opening Balance journals must use the fiscal year start date and matching configured initial opening date.';
        }

        foreach ($journal->lines as $index => $line) {
            if (! $this->isEligibleAccount($line->account)) {
                $errors["lines.{$index}.account_id"] = 'Opening Balance journals may use only eligible Balance Sheet ledger accounts.';
            }
        }

        return $errors;
    }
}
