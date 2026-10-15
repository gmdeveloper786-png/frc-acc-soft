<?php

namespace App\Services\Funds;

use App\Enums\AccountType;
use App\Enums\OperationalTransactionType;
use App\Models\Account;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Services\AccountingDecimal;
use App\Services\ManualJournalGuard;
use Illuminate\Validation\ValidationException;

/**
 * Restricted-fund rule (client requirement 5.4): a restricted fund may be
 * charged only to the ledgers it permits. A charge line is a debit to an
 * expense ledger, or to an asset ledger other than cash, bank or petty cash.
 * The rule is always enforced: a charge tagged with a restricted fund on a
 * ledger outside its permitted list is refused, and a restricted fund whose
 * permitted ledgers are not configured cannot be charged at all. There is no
 * warn-only mode. Unrestricted funds are never limited.
 */
class FundRestrictionPolicy
{
    public function __construct(private ManualJournalGuard $guard) {}

    /**
     * Lines that break the rule, keyed by the caller's line index.
     *
     * @param  array<int|string, array{fund_id?: mixed, account_id?: mixed, debit?: mixed}>  $lines
     * @return array<int|string, string>
     */
    public function violations(array $lines): array
    {
        $fundIds = collect($lines)->pluck('fund_id')->filter(fn ($id): bool => filled($id))->map(fn ($id): int => (int) $id)->unique()->all();
        if ($fundIds === []) {
            return [];
        }
        $funds = Fund::query()->with('permittedAccounts:id')->whereKey($fundIds)->where('type', Fund::TYPE_RESTRICTED)->get()->keyBy('id');
        if ($funds->isEmpty()) {
            return [];
        }
        $accounts = Account::query()->whereKey(collect($lines)->pluck('account_id')->filter()->all())->get(['id', 'code', 'name', 'account_type'])->keyBy('id');
        $controlled = array_map('intval', $this->guard->controlledAccountIds());

        $violations = [];
        foreach ($lines as $index => $line) {
            $fund = filled($line['fund_id'] ?? null) ? $funds->get((int) $line['fund_id']) : null;
            $account = filled($line['account_id'] ?? null) ? $accounts->get((int) $line['account_id']) : null;
            if ($fund === null || $account === null || ! $this->isCharge($account, $line['debit'] ?? 0, $controlled)) {
                continue;
            }
            if ($fund->permittedAccounts->isEmpty()) {
                $violations[$index] = "Restricted fund {$fund->code} has no permitted ledgers configured, so nothing can be charged to it. Set its permitted ledgers under Funds & Grants first.";
            } elseif (! $fund->permittedAccounts->contains('id', $account->id)) {
                $violations[$index] = "Ledger {$account->code} {$account->name} is not a permitted charge for restricted fund {$fund->code}.";
            }
        }

        return $violations;
    }

    /**
     * Refuse the lines when any of them breaks the rule.
     *
     * @param  array<int|string, array{fund_id?: mixed, account_id?: mixed, debit?: mixed}>  $lines
     * @param  \Closure(int|string): string  $errorKey  Validation key for a line index.
     */
    public function check(array $lines, \Closure $errorKey): void
    {
        $messages = [];
        foreach ($this->violations($lines) as $index => $message) {
            $messages[$errorKey($index)] = $message;
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * The line an operational transaction charges to its fund, or null when
     * it charges nothing: only payments, petty cash and expenses debit their
     * contra ledger. Receipts, donations and vendor payments do not.
     *
     * @return array{fund_id: mixed, account_id: mixed, debit: string}|null
     */
    public function transactionChargeLine(OperationalTransactionType $type, mixed $fundId, mixed $contraAccountId, string $amount): ?array
    {
        $charges = in_array($type, [OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment, OperationalTransactionType::PettyCash, OperationalTransactionType::Expense], true);

        return $charges && filled($fundId) ? ['fund_id' => $fundId, 'account_id' => $contraAccountId, 'debit' => $amount] : null;
    }

    /** @return list<string> Rule warnings for a saved operational transaction. */
    public function transactionWarnings(OperationalTransaction $transaction): array
    {
        $line = $this->transactionChargeLine($transaction->type, $transaction->fund_id, $transaction->contra_account_id, (string) $transaction->amount);

        return $line === null ? [] : array_values($this->violations([$line]));
    }

    /** @return list<string> Rule warnings for a journal's lines, numbered as on screen. */
    public function journalWarnings(JournalEntry $journal): array
    {
        $lines = $journal->lines->keyBy('line_number')->map(fn ($line): array => ['fund_id' => $line->fund_id, 'account_id' => $line->account_id, 'debit' => $line->debit])->all();

        return collect($this->violations($lines))->map(fn (string $message, int|string $number): string => "Line {$number}: {$message}")->values()->all();
    }

    /** @param list<int> $controlled */
    private function isCharge(Account $account, mixed $debit, array $controlled): bool
    {
        try {
            if (! AccountingDecimal::of($debit ?? 0)->isPositive()) {
                return false;
            }
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $account->account_type === AccountType::Expense
            || ($account->account_type === AccountType::Asset && ! in_array((int) $account->id, $controlled, true));
    }
}
