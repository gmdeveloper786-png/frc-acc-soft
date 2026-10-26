<?php

namespace App\Http\Requests;

class UpdateAccountRequest extends StoreAccountRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accounts.update') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->accountRules();
        // Structure is fixed after creation: these may be repeated but AccountService refuses any change to them.
        foreach (['code', 'parent_id', 'account_type', 'level', 'normal_balance', 'financial_statement', 'is_postable'] as $field) {
            $rules[$field] = ['sometimes', ...array_values(array_diff((array) $rules[$field], ['required']))];
        }
        $rules['code'] = ['sometimes', 'string', 'max:50'];

        return $rules;
    }

    public function messages(): array
    {
        return [];
    }
}
