<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInventoryCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory_items.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('inventory_categories', 'name')->ignore($this->route('inventoryCategory'))],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
