<?php

namespace App\Http\Requests;

use App\Rules\WithinFinancialMaximum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape checks only. Which record or ledger a purpose needs, and whether the
 * user may use that purpose, is decided by UnifiedPaymentService.
 */
class StoreUnifiedPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_source' => ['nullable', 'string', 'in:bank,cash,petty_cash'],
            'payment_purpose_id' => ['required', 'integer'],
            'payee_type_id' => ['nullable', 'integer'],
            'payee_vendor_id' => ['nullable', 'integer'],
            'payee_employee_id' => ['nullable', 'integer'],
            'payee_id' => ['nullable', 'integer'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'transaction_date' => ['required', 'date'],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'amount' => ['required', 'decimal:0,4', 'gt:0', new WithinFinancialMaximum],
            'purchase_bill_id' => ['nullable', 'integer'],
            'payable_obligation_id' => ['nullable', 'integer'],
            'settlement_request_token' => ['nullable', 'uuid'],
            'payroll_run_id' => ['nullable', 'integer'],
            'staff_advance_id' => ['nullable', 'integer'],
            'debit_account_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('is_active', true)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
