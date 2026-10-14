<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory_items.manage');
    }

    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', Rule::unique('inventory_items', 'item_code')->ignore($this->route('inventoryItem'))],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:inventory_categories,id'],
            'unit_of_measure' => ['required', 'string', 'max:30'],
            'minimum_stock_level' => ['nullable', 'decimal:0,4', 'gte:0', 'max:9999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['item_code.regex' => 'The item code may contain letters, numbers, dots, dashes, slashes and underscores only.'];
    }
}
