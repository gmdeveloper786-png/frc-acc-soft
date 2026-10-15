<?php

namespace App\Http\Requests;

use App\Enums\StaffAdvanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStaffAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('staff_advances.manage') && $this->user()->can('payroll.view_sensitive');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', Rule::enum(StaffAdvanceType::class)],
            'principal_amount' => ['required', 'decimal:0,4', 'gt:0'],
            'instalment_count' => ['nullable', 'required_without:instalment_amount', 'prohibits:instalment_amount', 'integer', 'min:1', 'max:120'],
            'instalment_amount' => ['nullable', 'required_without:instalment_count', 'decimal:0,4', 'gt:0'],
            'first_recovery_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
            'payroll_component_id' => ['required', 'integer', 'exists:payroll_components,id'],
            'purpose' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['instalment_count.prohibits' => 'Enter either the number of instalments or the instalment amount, not both.'];
    }
}
