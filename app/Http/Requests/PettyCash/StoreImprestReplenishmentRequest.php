<?php

namespace App\Http\Requests\PettyCash;

use Illuminate\Foundation\Http\FormRequest;

class StoreImprestReplenishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imprest.replenish') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            // Blank means the full replenishment due; an entered amount may not exceed it.
            'amount' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }
}
