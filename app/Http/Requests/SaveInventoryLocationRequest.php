<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory_items.manage');
    }

    public function rules(): array
    {
        return [
            'branch_id' => [$this->route('inventoryLocation') ? 'nullable' : 'required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
