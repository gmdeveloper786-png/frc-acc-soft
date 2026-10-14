<?php

namespace App\Http\Requests;

use App\Models\FixedAsset;
use Illuminate\Foundation\Http\FormRequest;

class SaveFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('fixedAsset') ? 'fixed_assets.update' : 'fixed_assets.create');
    }

    public function rules(): array
    {
        $descriptive = [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:150'],
            'custodian' => ['nullable', 'string', 'max:150'],
        ];
        $asset = $this->route('fixedAsset');
        if ($asset instanceof FixedAsset && ! $asset->status->isEditable()) {
            // Financial terms of an activated asset are frozen; the service rejects any attempt to change them.
            return $descriptive;
        }

        return [
            ...$descriptive,
            'category_id' => ['required', 'integer', 'exists:asset_categories,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'acquisition_date' => ['required', 'date'],
            'in_service_date' => ['required', 'date', 'after_or_equal:acquisition_date'],
            'acquisition_cost' => ['required', 'decimal:0,4', 'gt:0'],
            'residual_value' => ['required', 'decimal:0,4', 'gte:0', 'lte:acquisition_cost'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'opening_accumulated_depreciation' => ['nullable', 'decimal:0,4', 'gte:0'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'purchase_bill_id' => ['nullable', 'integer', 'exists:purchase_bills,id'],
        ];
    }

    public function attributes(): array
    {
        return ['category_id' => 'category', 'branch_id' => 'branch', 'vendor_id' => 'vendor', 'purchase_bill_id' => 'purchase bill', 'useful_life_months' => 'useful life'];
    }
}
