<?php

namespace App\Http\Requests\PettyCash;

use Illuminate\Foundation\Http\FormRequest;

class ImprestStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imprest.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
