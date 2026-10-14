<?php

namespace App\Http\Requests\FeePosting;

use Illuminate\Foundation\Http\FormRequest;

class PostFeeRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frc_integration.post') === true && $this->user()->can('journals.post');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ids.required' => 'Select at least one eligible record to post.'];
    }
}
