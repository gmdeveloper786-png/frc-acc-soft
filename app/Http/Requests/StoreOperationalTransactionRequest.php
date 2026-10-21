<?php

namespace App\Http\Requests;

use App\Enums\OperationalTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationalTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = OperationalTransactionType::tryFrom((string) $this->input('type'));

        return $type && $this->user()->can($type->permissionPrefix().'.create');
    }

    public function rules(): array
    {
        $type = OperationalTransactionType::tryFrom((string) $this->input('type'));
        $needsFinancial = $type !== OperationalTransactionType::Expense || $this->input('settlement_type') === 'paid';

        return [
            'type' => ['required', Rule::enum(OperationalTransactionType::class)],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'transaction_date' => ['required', 'date'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'financial_account_id' => [$needsFinancial ? 'required' : 'nullable', $type === OperationalTransactionType::Expense ? 'prohibited_if:settlement_type,accrued' : 'nullable', 'integer', 'exists:financial_accounts,id'],
            'contra_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'payable_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('is_active', true)],
            'donation_category_id' => [$type === OperationalTransactionType::Donation ? 'required' : 'nullable', 'integer', 'exists:donation_categories,id'],
            'purchase_bill_id' => [$type === OperationalTransactionType::VendorPayment ? 'required' : 'nullable', 'integer', 'exists:purchase_bills,id'],
            'payable_obligation_id' => ['nullable', 'integer', 'exists:payable_obligations,id'],
            'settlement_request_token' => ['nullable', 'uuid'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['required', 'string', 'max:2000'],
            'settlement_type' => [$type === OperationalTransactionType::Expense ? 'required' : 'nullable', Rule::in(['paid', 'accrued'])],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
