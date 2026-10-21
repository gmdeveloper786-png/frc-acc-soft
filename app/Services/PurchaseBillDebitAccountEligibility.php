<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PurchaseBillDebitAccountEligibility
{
    /** @return Builder<Account> */
    public function query(): Builder
    {
        return Account::query()->eligibleForPurchaseBillDebit();
    }

    public function assertEligible(int $accountId, string $field): void
    {
        if (! $this->query()->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages([
                $field => 'Purchase Bill debit line ke liye explicitly approved active, postable Expense ya Asset ledger required hai.',
            ]);
        }
    }
}
