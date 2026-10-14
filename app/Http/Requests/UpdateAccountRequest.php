<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

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
        $rules['code'] = ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('accounts', 'code')->ignore($this->route('account'))];

        return $rules;
    }
}
