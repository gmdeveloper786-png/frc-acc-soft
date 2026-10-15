<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PostYearEndClosingRequest extends FormRequest
{
    /**
     * Year-end closing is an organisation-level posting; the service re-checks every permission.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('fiscal_years.close') && $user->can('journals.post') && $user->can('branches.view_all');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
