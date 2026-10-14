<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('purchaseOrder') ? 'purchase_orders.update' : 'purchase_orders.create');
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'po_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:po_date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:30'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.discount_amount' => ['nullable', 'decimal:0,4', 'gte:0'],
            'lines.*.tax_amount' => ['nullable', 'decimal:0,4', 'gte:0'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'lines.*.description' => 'line description', 'lines.*.quantity' => 'line quantity', 'lines.*.unit_price' => 'line unit price',
            'lines.*.discount_amount' => 'line discount', 'lines.*.tax_amount' => 'line tax',
        ];
    }
}
