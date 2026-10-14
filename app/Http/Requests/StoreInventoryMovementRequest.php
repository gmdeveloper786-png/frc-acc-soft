<?php

namespace App\Http\Requests;

use App\Models\InventoryLocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Receive, issue, return and adjustment forms. The route decides the kind;
 * it is never taken from user input.
 */
class StoreInventoryMovementRequest extends FormRequest
{
    public const PERMISSIONS = ['receipt' => 'inventory.receive', 'issue' => 'inventory.issue', 'return' => 'inventory.return', 'adjustment' => 'inventory.adjust'];

    public function authorize(): bool
    {
        $permission = self::PERMISSIONS[$this->kind()] ?? null;

        return $permission !== null && $this->user()->can($permission);
    }

    public function kind(): string
    {
        return (string) $this->route('kind');
    }

    /** The form offers one "branch — location" list, so the branch follows the chosen location. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('branch_id') && is_numeric($this->input('location_id'))) {
            $this->merge(['branch_id' => InventoryLocation::query()->whereKey($this->input('location_id'))->value('branch_id')]);
        }
    }

    public function rules(): array
    {
        $kind = $this->kind();

        return [
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'issued_to' => [$kind === 'issue' ? 'required' : 'nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'uuid'],
            'notes' => [$kind === 'adjustment' ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
            'direction' => [$kind === 'adjustment' ? 'required' : 'exclude', 'in:increase,decrease'],
            'purchase_order_id' => [$kind === 'receipt' ? 'nullable' : 'exclude', 'integer', 'exists:purchase_orders,id'],
            'purchase_order_line_id' => [$kind === 'receipt' ? 'nullable' : 'exclude', 'integer', 'exists:purchase_order_lines,id'],
        ];
    }

    public function attributes(): array
    {
        return ['item_id' => 'item', 'location_id' => 'location', 'branch_id' => 'branch', 'issued_to' => 'recipient', 'purchase_order_id' => 'purchase order', 'purchase_order_line_id' => 'purchase order line'];
    }
}
