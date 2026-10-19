<?php

namespace App\Http\Requests;

use App\Models\FinancialAccount;
use App\Rules\AccountNumberOrIban;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['type', 'name', 'bank_name', 'account_title', 'account_number', 'currency', 'opening_reference'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim((string) $this->input($field));
            }
        }
        if (isset($normalized['currency'])) {
            $normalized['currency'] = strtoupper($normalized['currency']);
        }
        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user()->can('financial_accounts.manage');
    }

    public function rules(): array
    {
        $id = ($this->route('financialAccount') ?? $this->route('financial_account'))?->id;

        return [
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'name' => ['required', 'string', Rule::when($this->input('type') === 'bank', ['min:2']), 'max:255'],
            'account_id' => [
                'required', 'integer',
                // An organisational ledger may back one account per branch; branch balances come from the journal's branch.
                Rule::unique('financial_accounts')->where(fn ($query) => $query->where('branch_id', $this->integer('branch_id')))->ignore($id),
                $this->sameTypeOnLedger($id),
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('level', 'ledger')
                    ->where('account_type', 'asset')
                    ->where('normal_balance', 'debit')
                    ->where('financial_statement', 'balance_sheet')
                    ->where('financial_account_type', (string) $this->input('type'))
                    ->where('is_postable', true)
                    ->where('is_active', true)),
            ],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereIn('status', ['publish', 'active'])],
            'bank_name' => ['nullable', 'required_if:type,bank', 'string', 'max:255'],
            'account_title' => ['nullable', 'required_if:type,bank', 'string', 'max:255'],
            'account_number' => [Rule::requiredIf($this->input('type') === 'bank' && ! $this->canKeepExistingAccountNumber()), 'nullable', 'string', 'max:80', new AccountNumberOrIban],
            'keep_account_number' => ['sometimes', 'boolean'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'opening_reference' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.unique' => 'This branch already has a cash or bank account on this ledger. Each branch can link a ledger once, so its balance stays reconcilable.',
            'account_id.exists' => 'Select an active, posting-enabled Asset ledger classified for the chosen Cash or Bank account type.',
            'name.min' => 'Enter a meaningful bank account name of at least 2 characters.',
            'account_title.required_if' => 'Account title is required for a bank account.',
            'account_number.required' => 'Account number or IBAN is required for a bank account.',
            'account_number.required_if' => 'Account number or IBAN is required for a bank account.',
        ];
    }

    /** Cash and bank accounts never share a ledger, so every record on a ledger is of the same type. */
    private function sameTypeOnLedger(?int $id): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($id): void {
            $other = FinancialAccount::query()->where('account_id', (int) $value)->when($id, fn ($query) => $query->whereKeyNot($id))
                ->where('type', '!=', (string) $this->input('type'))->value('type');
            if ($other !== null) {
                $fail("This ledger is already used by {$other} accounts. Cash and bank accounts cannot share a ledger.");
            }
        };
    }

    private function canKeepExistingAccountNumber(): bool
    {
        $account = $this->route('financialAccount') ?? $this->route('financial_account');

        return $account instanceof FinancialAccount
            && $this->boolean('keep_account_number')
            && filled($account->account_number);
    }
}
