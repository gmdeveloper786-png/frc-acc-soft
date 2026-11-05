<?php

namespace App\Http\Requests\BankReconciliation;

use App\Models\BankStatement;
use App\Models\FinancialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        return [
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'bank_statement_id' => ['nullable', 'integer'],
            // Taken from the selected statement when one is chosen.
            'statement_date' => ['required_without:bank_statement_id', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'statement_closing_balance' => ['required_without:bank_statement_id', 'nullable', 'string', 'max:25', 'regex:'.ImportBankStatementRequest::AMOUNT_PATTERN],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $accountId = $this->integer('financial_account_id');
            $statementId = $this->input('bank_statement_id');

            if ($accountId) {
                $account = FinancialAccount::query()->find($accountId);
                if (! $account || $account->type !== 'bank') {
                    $validator->errors()->add('financial_account_id', 'The selected financial account must be a bank account.');
                }
            }

            if (filled($statementId) && $accountId) {
                $statement = BankStatement::query()
                    ->whereKey($statementId)
                    ->where('financial_account_id', $accountId)
                    ->where('status', BankStatement::IMPORTED)
                    ->first();

                if (! $statement) {
                    $validator->errors()->add('bank_statement_id', 'Select an imported statement of this bank account.');
                }
            }
        });
    }

    public function messages(): array
    {
        return ['statement_closing_balance.regex' => 'Enter the statement closing balance as an amount with at most two decimals.'];
    }
}
