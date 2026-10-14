<?php

namespace App\Http\Requests;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialStatement;
use App\Enums\NormalBalance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accounts.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->accountRules();
    }

    protected function accountRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'level' => ['required', Rule::enum(AccountLevel::class)],
            'normal_balance' => ['required', Rule::enum(NormalBalance::class)],
            'financial_statement' => ['required', Rule::enum(FinancialStatement::class)],
            'is_postable' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
