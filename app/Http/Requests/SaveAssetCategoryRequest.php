<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('asset_categories.manage');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('asset_categories', 'code')->ignore($this->route('assetCategory'))],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'asset_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'accumulated_depreciation_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:asset_account_id'],
            'depreciation_expense_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:asset_account_id', 'different:accumulated_depreciation_account_id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function attributes(): array
    {
        return [
            'asset_account_id' => 'asset account', 'accumulated_depreciation_account_id' => 'accumulated depreciation account',
            'depreciation_expense_account_id' => 'depreciation expense account', 'default_useful_life_months' => 'default useful life',
        ];
    }
}
