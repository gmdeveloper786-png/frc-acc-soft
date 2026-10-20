<?php

namespace App\Http\Requests;

use App\Rules\WithinFinancialMaximum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('opening_balances.prepare') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'opening_date' => ['required', 'date_format:Y-m-d'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', 'distinct', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'decimal:0,2', 'min:0', new WithinFinancialMaximum],
            'lines.*.credit' => ['nullable', 'decimal:0,2', 'min:0', new WithinFinancialMaximum],
            'lines.*.source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
