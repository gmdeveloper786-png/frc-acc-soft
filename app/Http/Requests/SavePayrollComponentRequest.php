<?php

namespace App\Http\Requests;

use App\Enums\PayrollComponentCategory;
use App\Enums\PayrollComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePayrollComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payroll_components.manage');
    }

    public function rules(): array
    {
        return [
            'code' => [Rule::requiredIf($this->route('payrollComponent') === null), 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('payroll_components', 'code')->ignore($this->route('payrollComponent'))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PayrollComponentType::class)],
            'category' => ['required', Rule::enum(PayrollComponentCategory::class)],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
