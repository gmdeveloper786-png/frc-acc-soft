<?php

namespace App\Http\Requests\BankReconciliation;

use Illuminate\Foundation\Http\FormRequest;

class AutoMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank_reconciliation.prepare');
    }

    public function rules(): array
    {
        return [
            'same_reference' => ['nullable', 'boolean'],
            'pairs' => ['nullable', 'array', 'max:2000'],
            'pairs.*' => ['string', 'regex:/^\d+:\d+$/'],
        ];
    }
}
