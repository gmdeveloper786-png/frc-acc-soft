<?php

namespace App\Http\Requests\BankReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class ClearBroughtForwardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        return [
            'journal_line_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
