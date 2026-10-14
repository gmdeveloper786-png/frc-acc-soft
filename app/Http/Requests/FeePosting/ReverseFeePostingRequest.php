<?php

namespace App\Http\Requests\FeePosting;

use Illuminate\Foundation\Http\FormRequest;

class ReverseFeePostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frc_integration.reverse') === true && $this->user()->can('journals.reverse');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accounting_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
