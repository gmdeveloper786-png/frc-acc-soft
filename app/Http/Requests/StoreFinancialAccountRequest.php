<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('financial_accounts.manage');
    }

    public function rules(): array
    {
        $id = ($this->route('financialAccount') ?? $this->route('financial_account'))?->id;

        return [
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', Rule::unique('financial_accounts')->ignore($id), Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('level', 'ledger')->where('account_type', 'asset')->where('is_postable', true)->where('is_active', true))],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereIn('status', ['publish', 'active'])],
            'bank_name' => ['nullable', 'required_if:type,bank', 'string', 'max:255'],
            'account_title' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3'],
            'opening_reference' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
