<?php

namespace App\Http\Requests;

use App\Models\InventoryLocation;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.transfer');
    }

    /** Each side is chosen from one "branch — location" list, so its branch follows the location. */
    protected function prepareForValidation(): void
    {
        foreach (['from', 'to'] as $side) {
            if (! $this->filled("{$side}_branch_id") && is_numeric($this->input("{$side}_location_id"))) {
                $this->merge(["{$side}_branch_id" => InventoryLocation::query()->whereKey($this->input("{$side}_location_id"))->value('branch_id')]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'from_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_location_id' => ['required', 'integer', 'exists:inventory_locations,id', 'different:from_location_id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['item_id' => 'item', 'from_location_id' => 'source location', 'to_location_id' => 'destination location', 'from_branch_id' => 'source branch', 'to_branch_id' => 'destination branch'];
    }
}
