<?php

namespace App\Http\Requests\Funds;

use Illuminate\Foundation\Http\FormRequest;

class FundReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fund_reports.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A branch id, or "all" for the consolidated view. Access is enforced by FundReportScope.
            'branch_id' => ['nullable', 'regex:/^(all|\d+)$/'],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'as_of' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
            'branch_id.regex' => 'Choose a valid branch.',
        ];
    }
}
