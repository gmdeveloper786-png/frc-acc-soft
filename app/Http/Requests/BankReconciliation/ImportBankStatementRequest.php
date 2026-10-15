<?php

namespace App\Http\Requests\BankReconciliation;

use App\Services\BankReconciliation\StatementImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportBankStatementRequest extends FormRequest
{
    /** Signed amount with at most two decimals; thousands separators allowed. */
    public const AMOUNT_PATTERN = '/^\s*[+-]?[\d,]*\d(\.\d{1,2})?\s*$/';

    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        $column = ['nullable', 'string', 'max:3', 'regex:/^[A-Z]{1,3}$/'];

        return [
            'date_column' => ['required', 'string', 'max:3', 'regex:/^[A-Z]{1,3}$/'],
            'description_column' => $column,
            'reference_column' => $column,
            'amount_mode' => ['required', Rule::in(['split', 'signed'])],
            'deposit_column' => [...$column, 'required_if:amount_mode,split'],
            'withdrawal_column' => [...$column, 'required_if:amount_mode,split'],
            'amount_column' => [...$column, 'required_if:amount_mode,signed'],
            'balance_column' => $column,
            'date_format' => ['required', Rule::in(array_keys(StatementImportService::DATE_FORMATS))],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'opening_balance' => ['required', 'string', 'max:25', 'regex:'.self::AMOUNT_PATTERN],
            'closing_balance' => ['required', 'string', 'max:25', 'regex:'.self::AMOUNT_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'opening_balance.regex' => 'Enter the opening balance as an amount with at most two decimals.',
            'closing_balance.regex' => 'Enter the closing balance as an amount with at most two decimals.',
        ];
    }
}
