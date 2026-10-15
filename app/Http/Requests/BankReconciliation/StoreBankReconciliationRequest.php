<?php

namespace App\Http\Requests\BankReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        return [
            'financial_account_id' => ['required', 'integer'],
            'bank_statement_id' => ['nullable', 'integer'],
            // Taken from the selected statement when one is chosen.
            'statement_date' => ['required_without:bank_statement_id', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'statement_closing_balance' => ['required_without:bank_statement_id', 'nullable', 'string', 'max:25', 'regex:'.ImportBankStatementRequest::AMOUNT_PATTERN],
        ];
    }

    public function messages(): array
    {
        return ['statement_closing_balance.regex' => 'Enter the statement closing balance as an amount with at most two decimals.'];
    }
}
