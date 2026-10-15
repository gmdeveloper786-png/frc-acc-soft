<?php

namespace App\Http\Requests\PettyCash;

use Illuminate\Foundation\Http\FormRequest;

class SaveImprestFloatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imprest.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'branch_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:branches,id'],
            'account_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:accounts,id'],
            'float_amount' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999'],
            'source_financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'custodian_name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
