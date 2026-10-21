<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdatePurchaseBillRequest extends StorePurchaseBillRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase_bills.update');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $bill = $this->route('purchase_bill') ?? $this->route('purchaseBill');

        $rules['bill_number'] = [
            'required',
            'string',
            'max:100',
            Rule::unique('purchase_bills')->ignore($bill?->id)->where(fn ($query) => $query
                ->where('vendor_id', $this->integer('vendor_id'))
                ->where('branch_id', $this->integer('branch_id'))),
        ];
        $rules['expected_updated_at'] = ['required', 'date_format:Y-m-d H:i:s.u'];

        return $rules;
    }
}
