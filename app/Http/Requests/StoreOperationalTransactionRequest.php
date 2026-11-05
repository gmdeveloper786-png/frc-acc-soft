<?php

namespace App\Http\Requests;

use App\Enums\OperationalTransactionType;
use App\Models\DonationCategory;
use App\Models\Fund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $isExpense = $type === OperationalTransactionType::Expense;
        $needsFinancial = $type !== OperationalTransactionType::Expense || $this->input('settlement_type') === 'paid';

        return [
            'type' => ['required', Rule::enum(OperationalTransactionType::class)],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'transaction_date' => ['required', 'date'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'financial_account_id' => [
                $needsFinancial ? 'required' : 'nullable',
                $isExpense && $this->input('settlement_type') === 'accrued' ? 'prohibited' : 'nullable',
                'integer',
                'exists:financial_accounts,id',
            ],
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
            'settlement_type' => [
                $isExpense ? 'required' : 'nullable',
                $isExpense ? Rule::in(['accrued']) : Rule::in(['paid', 'accrued']),
            ],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'settlement_type.in' => 'Direct payments must be created from Payments. Expenses is used to record accrued expenses.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('type') === OperationalTransactionType::Donation->value) {
                $categoryId = $this->integer('donation_category_id');
                $fundId = $this->filled('fund_id') ? $this->integer('fund_id') : null;

                if ($categoryId) {
                    $category = DonationCategory::query()->find($categoryId);
                    if ($category) {
                        $isZakatCategory = $category->slug === 'zakat' || str_contains(strtolower($category->name), 'zakat');
                        $isProjectCategory = $category->slug === 'new-frc-project-donation' || str_contains(strtolower($category->name), 'project');
                        $isGeneralCategory = ! $isZakatCategory && ! $isProjectCategory;

                        $effectiveFundId = $fundId ?: $category->fund_id;
                        $fund = $effectiveFundId ? Fund::query()->with('parent')->find($effectiveFundId) : null;

                        // Policy 1: Mandatory ZKT for Zakat
                        if ($isZakatCategory) {
                            $actualFund = $fundId ? Fund::query()->with('parent')->find($fundId) : null;
                            if (! $fundId || ! $actualFund || ($actualFund->code !== 'ZKT' && $actualFund->category !== Fund::CATEGORY_ZAKAT)) {
                                $validator->errors()->add(
                                    'fund_id',
                                    'Zakat donations must be allocated to the ZKT (Zakat / Charity) fund.'
                                );
                            }
                        }

                        // Policy 2: Project-specific child fund under DNR
                        if ($isProjectCategory) {
                            if (! $fundId) {
                                $validator->errors()->add(
                                    'fund_id',
                                    'New FRC Project Donations must be allocated to an active project child fund under DNR.'
                                );
                            } elseif (! $fund || ! $fund->is_active) {
                                $validator->errors()->add(
                                    'fund_id',
                                    'The selected project fund is not active.'
                                );
                            } elseif (! $fund->parent_id || ($fund->parent?->code !== 'DNR' && $fund->parent?->category !== 'donor')) {
                                $validator->errors()->add(
                                    'fund_id',
                                    'New FRC Project Donations must be allocated to an active project child fund under DNR. Generic parent DNR cannot be used.'
                                );
                            }
                        }

                        // Policy 5: Fund Compatibility & Donor restriction evidence
                        if ($fund) {
                            $isZakatFund = $fund->category === Fund::CATEGORY_ZAKAT || $fund->code === 'ZKT';
                            $isGrantFund = $fund->category === Fund::CATEGORY_GRANT || str_starts_with($fund->code, 'GRT');
                            $isDnrOrChild = $fund->code === 'DNR' || $fund->parent?->code === 'DNR' || $fund->category === 'donor' || $fund->parent?->category === 'donor';

                            if ($isZakatFund && ! $isZakatCategory) {
                                $validator->errors()->add(
                                    'fund_id',
                                    "Restricted fund {$fund->code} ({$fund->name}) can only receive Zakat donations. It cannot be selected for {$category->name}."
                                );
                            }

                            if ($isGrantFund) {
                                $validator->errors()->add(
                                    'fund_id',
                                    "Grant project fund {$fund->code} is reserved for contractual grants. Donations cannot be allocated to grant funds."
                                );
                            }

                            if ($isGeneralCategory && $isDnrOrChild && ! $this->hasFile('attachment')) {
                                $validator->errors()->add(
                                    'attachment',
                                    'Allocating a General Donation to a donor-restricted fund requires an attached donor-written restriction document.'
                                );
                            }
                        }
                    }
                }
            }
        });
    }
}
