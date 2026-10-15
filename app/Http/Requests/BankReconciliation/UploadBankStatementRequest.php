<?php

namespace App\Http\Requests\BankReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class UploadBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        return [
            'financial_account_id' => ['required', 'integer'],
            // 5 MB; csv files are commonly detected as plain text.
            'statement_file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx'],
        ];
    }

    public function messages(): array
    {
        return ['statement_file.extensions' => 'Upload a .csv or .xlsx bank statement.', 'statement_file.mimes' => 'The file content is not a CSV or Excel file.'];
    }
}
