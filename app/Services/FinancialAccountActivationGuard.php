<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FinancialAccount;
use App\Rules\AccountNumberOrIban;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FinancialAccountActivationGuard
{
    public function assertCanActivate(FinancialAccount $financialAccount): void
    {
        $type = trim($financialAccount->type);
        $validator = Validator::make([
            'type' => $type,
            'name' => trim($financialAccount->name),
            'account_id' => $financialAccount->account_id,
            'branch_id' => $financialAccount->branch_id,
            'bank_name' => is_string($financialAccount->bank_name) ? trim($financialAccount->bank_name) : null,
            'account_title' => is_string($financialAccount->account_title) ? trim($financialAccount->account_title) : null,
            'account_number' => is_string($financialAccount->account_number) ? trim($financialAccount->account_number) : null,
            'currency' => strtoupper(trim($financialAccount->currency)),
        ], [
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'name' => ['required', 'string', Rule::when($type === 'bank', ['min:2']), 'max:255'],
            'account_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereIn('status', ['publish', 'active'])],
            'bank_name' => [Rule::requiredIf($type === 'bank'), 'nullable', 'string', 'max:255'],
            'account_title' => [Rule::requiredIf($type === 'bank'), 'nullable', 'string', 'max:255'],
            'account_number' => [Rule::requiredIf($type === 'bank'), 'nullable', 'string', 'max:80', new AccountNumberOrIban],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);

        $validator->after(function ($validator) use ($financialAccount, $type): void {
            $eligibleLedger = Account::query()->whereKey($financialAccount->account_id)
                ->where('level', 'ledger')->where('account_type', 'asset')->where('normal_balance', 'debit')
                ->where('financial_statement', 'balance_sheet')->where('financial_account_type', $type)
                ->where('is_postable', true)->where('is_active', true)->exists();
            if (! $eligibleLedger) {
                $validator->errors()->add('account_id', 'The linked GL ledger is not eligible for this financial account type.');
            }

            $duplicate = FinancialAccount::query()->whereKeyNot($financialAccount->id)
                ->where('account_id', $financialAccount->account_id)->where('branch_id', $financialAccount->branch_id)->exists();
            if ($duplicate) {
                $validator->errors()->add('account_id', 'This branch already has a financial account linked to the GL ledger.');
            }

            $incompatibleType = FinancialAccount::query()->whereKeyNot($financialAccount->id)
                ->where('account_id', $financialAccount->account_id)->where('type', '!=', $type)->exists();
            if ($incompatibleType) {
                $validator->errors()->add('account_id', 'Cash and bank accounts cannot share an incompatible GL ledger.');
            }
        });

        $validator->validate();
    }
}
