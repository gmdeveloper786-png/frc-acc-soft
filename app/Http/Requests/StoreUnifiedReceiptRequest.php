<?php

namespace App\Http\Requests;

use App\Enums\OperationalTransactionType;
use App\Models\FinancialAccount;
use App\Services\PettyCash\ImprestLedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUnifiedReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $method = $this->input('method') ?? $this->input('receiving_method') ?? $this->input('type');

        $normalizedType = match ($method) {
            'bank', 'bank_receipt' => OperationalTransactionType::BankReceipt->value,
            default => OperationalTransactionType::CashReceipt->value,
        };

        $this->merge([
            'type' => $normalizedType,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('receipts.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([OperationalTransactionType::CashReceipt->value, OperationalTransactionType::BankReceipt->value])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'contra_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('is_active', true)],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['required', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $accountId = $this->integer('financial_account_id');
            if (! $accountId) {
                return;
            }

            $financialAccount = FinancialAccount::query()->find($accountId);
            if (! $financialAccount) {
                return;
            }

            $type = $this->input('type');
            $expectedAccountType = $type === OperationalTransactionType::BankReceipt->value ? 'bank' : 'cash';

            if ($financialAccount->type !== $expectedAccountType) {
                $validator->errors()->add(
                    'financial_account_id',
                    "The selected receiving account must be a {$expectedAccountType} account for {$type}."
                );
            }

            if ($financialAccount->type === 'cash' && app(ImprestLedgerService::class)->activeFloatFor($financialAccount)) {
                $validator->errors()->add(
                    'financial_account_id',
                    'This cash account holds an imprest float: only petty cash vouchers may use it, and it is refilled through Imprest replenishment.'
                );
            }
        });
    }
}
