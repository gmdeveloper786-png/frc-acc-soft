<?php

namespace App\Http\Requests\BankReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class RejectBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.approve');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
