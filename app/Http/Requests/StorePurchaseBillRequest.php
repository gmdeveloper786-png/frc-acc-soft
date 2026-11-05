<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase_bills.create');
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'payable_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('is_active', true)],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'po_exception_reason' => ['nullable', 'string', 'min:10', 'max:2000'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'bill_number' => ['required', 'string', 'max:100', Rule::unique('purchase_bills')->where(fn ($query) => $query->where('vendor_id', $this->integer('vendor_id'))->where('branch_id', $this->integer('branch_id')))],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'purchase_order_reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.unit_amount' => ['required', 'decimal:0,4', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.account_id.required' => 'Har bill line ke liye expense ya asset ledger select karna lazmi hai.',
            'lines.*.account_id.integer' => 'Valid expense ya asset ledger select karein.',
            'lines.*.account_id.exists' => 'Muntakhib kardah ledger account mojood nahi hai.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines.*.account_id' => 'ledger account',
        ];
    }
}
