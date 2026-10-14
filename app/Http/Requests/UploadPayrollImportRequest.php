<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadPayrollImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payroll.import');
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'accounting_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
            // 5 MB; csv files are commonly detected as plain text.
            'workbook' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv', 'mimes:xlsx,xls,csv,txt'],
        ];
    }

    public function messages(): array
    {
        return ['workbook.extensions' => 'Upload an .xlsx, .xls or .csv file.', 'workbook.mimes' => 'The file content is not a spreadsheet or CSV file.'];
    }
}
