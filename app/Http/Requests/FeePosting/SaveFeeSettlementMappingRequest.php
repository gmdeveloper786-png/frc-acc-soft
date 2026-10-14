<?php

namespace App\Http\Requests\FeePosting;

use App\Services\FeePosting\FeeSettlementMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFeeSettlementMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('fee_settlement.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'payment_method' => ['required', 'string', Rule::in(array_keys(FeeSettlementMappingService::METHODS))],
            'financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
        ];
    }
}
