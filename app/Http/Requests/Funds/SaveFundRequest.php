<?php

namespace App\Http\Requests\Funds;

use App\Models\Fund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $this->isMethod('post')
            ? (bool) $user?->can('funds.manage')
            : (bool) ($user?->can('funds.manage') || $user?->can('grants.manage'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A user without funds.manage may only change the grant fields; the other fields are ignored.
        $required = $this->user()->can('funds.manage') ? 'required' : 'nullable';
        $fund = $this->route('fund');

        return [
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('funds', 'code')->ignore($fund)],
            'name' => [$required, 'string', 'max:150'],
            'type' => [$required, Rule::in(array_keys(Fund::TYPES))],
            'category' => [$required, Rule::in(array_keys(Fund::CATEGORIES))],
            'parent_id' => ['nullable', 'integer', 'exists:funds,id'],
            'restriction_notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'permitted_account_ids' => ['nullable', 'array', 'max:500'],
            'permitted_account_ids.*' => ['integer', 'exists:accounts,id'],
            'donor_name' => ['nullable', 'string', 'max:255'],
            'grant_amount' => ['nullable', 'decimal:0,4', 'min:0'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['parent_id' => 'parent fund', 'permitted_account_ids' => 'permitted ledgers', 'donor_name' => 'donor / grantor'];
    }
}
