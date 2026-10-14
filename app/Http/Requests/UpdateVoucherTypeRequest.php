<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoucherTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('voucher_types.manage') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('voucher_types', 'prefix')->ignore($this->route('voucherType'))],
            'year_format' => ['required', Rule::in(['Y', 'y', 'none'])],
            'padding' => ['required', 'integer', 'between:3,10'],
            'reset_period' => ['required', Rule::in(['yearly', 'global'])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
