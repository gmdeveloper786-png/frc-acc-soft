<?php

namespace App\Http\Requests;

use App\Rules\WithinFinancialMaximum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('post') ? 'journals.create' : 'journals.update') === true;
    }

    public function rules(): array
    {
        return [
            'voucher_type_id' => ['required', 'integer', Rule::exists('voucher_types', 'id')->where('is_active', true)],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'accounting_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'lines.*.fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('is_active', true)],
            'lines.*.debit' => ['nullable', 'decimal:0,4', 'min:0', new WithinFinancialMaximum],
            'lines.*.credit' => ['nullable', 'decimal:0,4', 'min:0', new WithinFinancialMaximum],
        ];
    }
}
