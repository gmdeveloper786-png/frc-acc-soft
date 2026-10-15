<?php

namespace App\Http\Requests\BankReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class MatchStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        return [
            'statement_line_id' => ['required', 'integer'],
            'journal_line_id' => ['required', 'integer'],
        ];
    }
}
