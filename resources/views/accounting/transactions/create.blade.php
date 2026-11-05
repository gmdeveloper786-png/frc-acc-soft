@extends('layouts.app') @section('title','Create '.$type->label()) @section('page-title','Create '.$type->label())
@section('page-description', in_array($type->value, ['cash_receipt', 'bank_receipt'], true) ? 'Posting requires an open accounting period and a configured credit ledger or default GL mapping.' : 'Posting requires an open accounting period and approved GL mappings.')
@php
    $requiredFinancialAccountType = match($type->value) { 'cash_receipt', 'cash_payment', 'petty_cash' => 'cash', 'bank_receipt', 'bank_payment' => 'bank', default => null };
    $isCashReceipt = $type->value === 'cash_receipt';
    $isBankReceipt = $type->value === 'bank_receipt';
    $isReceipt = $isCashReceipt || $isBankReceipt;
@endphp
@section('content')<div class="card"><div class="card-body"><form method="POST" enctype="multipart/form-data" action="{{ route('transactions.store') }}">@csrf<input type="hidden" name="type" value="{{ $type->value }}"><div class="row g-3">
@if($type->value==='vendor_payment')
    {{-- The branch of a vendor payment is the branch of its purchase bill; the server checks it again. --}}
    <div class="col-md-4">
        <label class="form-label" for="vendor-payment-branch-name">Branch</label>
        <input class="form-control @error('branch_id') is-invalid @enderror" id="vendor-payment-branch-name" value="{{ $selectedBill?->branch?->name }}" placeholder="Taken from the purchase bill" readonly>
        <input type="hidden" id="transaction-branch" name="branch_id" value="{{ $selectedBill?->branch_id }}">
        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@else
<div class="col-md-4"><label class="form-label">{{ $isReceipt ? 'Receiving Branch' : 'Branch' }}</label><select class="form-select" id="transaction-branch" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
@endif
<div class="col-md-4"><label class="form-label">{{ $isReceipt ? 'Receipt Date' : 'Date' }}</label><input class="form-control" type="date" name="transaction_date" value="{{ old('transaction_date',today()->toDateString()) }}" required></div><div class="col-md-4"><label class="form-label">{{ $isReceipt ? 'Amount Received (PKR)' : 'Amount (PKR)' }}</label><input class="form-control text-end" name="amount" value="{{ old('amount') }}" required></div>
@if($type->value==='expense')
<div class="col-md-4">
    <label class="form-label">Settlement</label>
    <select class="form-select" id="expense-settlement" name="settlement_type">
        <option value="accrued" selected>Record as payable</option>
    </select>
    <div class="form-text">
        @if($canDirectPay ?? false)
            To pay directly via Bank, Cash, or Petty Cash, use <a href="{{ route('payments.create') }}">Unified Payments</a>.
        @else
            Direct payments must be created from Unified Payments.
        @endif
    </div>
</div>
@endif
<div class="col-md-6" id="financial-account-container" @if($type->value==='expense') hidden @endif><label class="form-label">{{ $isCashReceipt ? 'Receiving Cash Account' : ($isBankReceipt ? 'Receiving Bank Account' : 'Cash / Bank account') }}</label><select class="form-select @error('financial_account_id') is-invalid @enderror" id="transaction-financial-account" name="financial_account_id" @if($type->value==='expense') disabled @endif><option value="">Select account</option>@foreach($financialAccounts as $fa)<option value="{{ $fa->id }}" data-branch-id="{{ $fa->branch_id }}" data-shared="{{ $fa->isShared() ? '1' : '0' }}" data-account-type="{{ $fa->type }}" @selected(old('financial_account_id')==$fa->id)>{{ $fa->name }} ({{ ucfirst($fa->type) }}{{ $fa->isShared() ? ' — all branches' : '' }})</option>@endforeach</select>@error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror @if($isReceipt)<div class="form-text">Select the account where the money was received.</div>@endif</div>
@if(!in_array($type->value,['donation','vendor_payment']))<div class="col-md-6"><label class="form-label">{{ $isReceipt ? 'Credit Ledger / Receipt Against' : 'Contra / expense account' }}</label><select class="form-select @error('contra_account_id') is-invalid @enderror" name="contra_account_id"><option value="">{{ $isReceipt ? 'Use system default mapped ledger (if configured)' : 'Use GL mapping default' }}</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}" @selected((string) old('contra_account_id') === (string) $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select>@error('contra_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror @if($isReceipt)<div class="form-text">Select the accounting ledger to credit against this receipt, such as income or an existing receivable.</div>@endif</div>@endif
@if($type->value==='expense')<div class="col-md-6"><label class="form-label">Payable account (for accrued)</label><select class="form-select @error('payable_account_id') is-invalid @enderror" name="payable_account_id"><option value="">Use Accounts Payable mapping</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}" @selected((string) old('payable_account_id') === (string) $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select>@error('payable_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>@endif
@if($type->value==='donation')<div class="col-md-6"><label class="form-label">Donation category</label><select class="form-select @error('donation_category_id') is-invalid @enderror" id="transaction-donation-category" name="donation_category_id" required>@foreach($donationCategories as $cat)<option value="{{ $cat->id }}" data-slug="{{ $cat->slug }}" data-fund-id="{{ $cat->fund_id ?? '' }}" data-fund-code="{{ $cat->fund?->code ?? '' }}" data-fund-name="{{ $cat->fund?->name ?? '' }}" @selected(old('donation_category_id', $donationCategories->first()?->id) == $cat->id)>{{ $cat->name }}{{ $cat->income_account_id?'':' (mapping required)' }}</option>@endforeach</select>@error('donation_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>@endif
@if($type->value==='vendor_payment')
    <div class="col-md-6">
        <label class="form-label" for="vendor-payment-bill">Posted purchase bill</label>
        <select class="form-select @error('purchase_bill_id') is-invalid @enderror" id="vendor-payment-bill" name="purchase_bill_id" required>
            <option value="">Select posted purchase bill</option>
            @foreach($bills as $bill)
                <option value="{{ $bill->id }}" data-branch-id="{{ $bill->branch_id }}" data-branch-name="{{ $bill->branch->name }}" data-vendor-id="{{ $bill->vendor_id }}" data-vendor-name="{{ $bill->vendor->name }}" data-outstanding="{{ number_format((float) $bill->outstandingAmount(), 2) }}" @selected($selectedBill?->id === $bill->id)>{{ $bill->vendor->name }} — {{ $bill->bill_number }} — Outstanding PKR {{ number_format((float) $bill->outstandingAmount(), 2) }}</option>
            @endforeach
        </select>
        @error('purchase_bill_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text" id="vendor-payment-outstanding">
            @if($selectedBill)
                Outstanding: PKR {{ number_format((float) $selectedBill->outstandingAmount(), 2) }}
            @endif
        </div>
    </div>
@endif
<div class="col-md-6"><label class="form-label">{{ $isReceipt ? 'Received From (Person / Organization)' : 'Party / recipient / donor' }}</label><input class="form-control" name="party_name" value="{{ old('party_name') }}"></div>@if($type->value==='vendor_payment')
    {{-- The vendor of a vendor payment is the vendor of its purchase bill; the server takes it from the bill. --}}
    <div class="col-md-6">
        <label class="form-label" for="vendor-payment-vendor-name">Vendor</label>
        <input class="form-control @error('vendor_id') is-invalid @enderror" id="vendor-payment-vendor-name" value="{{ $selectedBill?->vendor?->name }}" placeholder="Taken from the purchase bill" readonly>
        <input type="hidden" id="vendor-payment-vendor" name="vendor_id" value="{{ $selectedBill?->vendor_id }}">
        @error('vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@else
<div class="col-md-6"><label class="form-label">{{ $isReceipt ? 'Related Vendor (optional)' : 'Vendor (optional)' }}</label><select class="form-select" name="vendor_id"><option value="">None</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('vendor_id')==$vendor->id)>{{ $vendor->name }}</option>@endforeach</select>@if($isReceipt)<div class="form-text">Select only when this receipt is related to a vendor.</div>@endif</div>
@endif
@php
    $selectedCategory = $type->value === 'donation'
        ? ($donationCategories->firstWhere('id', old('donation_category_id')) ?? $donationCategories->first())
        : null;
    $genFund = $funds->firstWhere('code', 'GEN') ?? $funds->firstWhere('category', 'general');
    $initialFundId = old('fund_id', match($type->value) {
        'donation' => $selectedCategory?->fund_id,
        'expense', 'petty_cash' => $genFund?->id,
        default => null,
    });
    $selectedCategorySlug = $selectedCategory?->slug ?? '';

    $activeProjectFunds = $funds->filter(fn ($f) => (bool) ($f->is_active && $f->parent_id && ($f->parent?->code === 'DNR' || $f->parent?->category === 'donor')));
    $hasActiveProjectFunds = $activeProjectFunds->isNotEmpty();

    $initialFundLabel = match($selectedCategorySlug) {
        'zakat' => 'Select ZKT — Zakat / Charity Fund',
        'new-frc-project-donation' => $hasActiveProjectFunds
            ? 'Select active project child fund under DNR'
            : 'No active project funds available — contact an authorized Fund Manager',
        default => $selectedCategory?->fund ? 'Use category default ('.$selectedCategory->fund->code.' — '.$selectedCategory->fund->name.')' : 'No default fund (select a fund or leave unallocated)',
    };
    $isRestrictedCategory = in_array($selectedCategorySlug, ['zakat', 'new-frc-project-donation'], true);
    $selectedFund = $funds->firstWhere('id', (int) $initialFundId);
    $isInitialDnrOrChild = $selectedFund && (
        $selectedFund->code === 'DNR' ||
        $selectedFund->parent?->code === 'DNR' ||
        $selectedFund->category === 'donor'
    );
    $initialAttachmentRequired = ($type->value === 'donation')
        && ! in_array($selectedCategorySlug, ['zakat', 'new-frc-project-donation'], true)
        && $isInitialDnrOrChild;

    $initialAttachmentHelp = match(true) {
        $type->value !== 'donation' => $isReceipt ? 'Select an attachment if applicable.' : '',
        $selectedCategorySlug === 'zakat' => 'Optional: Attach bank deposit slip, cheque image, or donor Zakat declaration if available.',
        $selectedCategorySlug === 'new-frc-project-donation' => 'Donor project confirmation required before processing. Attach donor project pledge/agreement, or confirm project attribution via child fund selection.',
        $isInitialDnrOrChild => 'Mandatory: Attach donor-written restriction letter when allocating General Donations to a restricted project fund.',
        default => 'Optional: Attach donor acknowledgment, deposit slip, or payment confirmation if available.',
    };

    $initialFundHelp = match(true) {
        $type->value !== 'donation' => in_array($type->value, ['expense', 'petty_cash'], true) ? 'Ordinary operating expenses default to GEN (General Fund). Select a restricted project fund only for eligible project activities.' : ($isReceipt ? 'Select the relevant fund if applicable.' : ''),
        $selectedCategorySlug === 'zakat' => 'Mandatory: Every Zakat donation must use the ZKT — Zakat / Charity Fund.',
        $selectedCategorySlug === 'new-frc-project-donation' => $hasActiveProjectFunds
            ? 'Mandatory: Project donations must select a specific active child fund under DNR (parent DNR alone is not permitted).'
            : 'No active project funds available under DNR. Please contact an authorized Fund Manager to set up an active project fund before proceeding.',
        default => 'General donations default to GEN (General Fund). Allocating to a restricted fund requires an attached donor-written restriction.',
    };

    $fundsMap = $funds->mapWithKeys(fn ($f) => [(string) $f->id => [
        'id' => $f->id,
        'code' => $f->code,
        'name' => $f->name,
        'parentId' => $f->parent_id,
        'parentCode' => $f->parent?->code,
        'category' => $f->category,
        'isActive' => (bool) $f->is_active,
        'isProjectChild' => (bool) ($f->parent_id && ($f->parent?->code === 'DNR' || $f->parent?->category === 'donor')),
    ]])->all();
@endphp
<div class="col-md-6"><label class="form-label">{{ $isReceipt ? 'Service Allocation (optional)' : 'Service allocation (optional)' }}</label><select class="form-select @error('service_id') is-invalid @enderror" name="service_id"><option value="">None</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected((string) old('service_id') === (string) $service->id)>{{ $service->name }}</option>@endforeach</select>@error('service_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><div class="col-md-6"><label class="form-label" id="transaction-fund-label">{{ $type->value==='donation' ? 'Fund Allocation' : ($isReceipt ? 'Fund Allocation (optional)' : 'Fund (optional)') }}<span class="text-danger ms-1 @if(! $isRestrictedCategory) d-none @endif" id="transaction-fund-required">*</span></label><select class="form-select @error('fund_id') is-invalid @enderror" id="transaction-fund" name="fund_id"><option value="" id="transaction-fund-default-option"@if($selectedCategorySlug === 'zakat') disabled @endif>{{ $type->value==='donation' ? $initialFundLabel : 'No fund' }}</option>@foreach($funds as $fund)@php
    $isProjectChild = (bool) ($fund->parent_id && ($fund->parent?->code === 'DNR' || $fund->parent?->category === 'donor'));
    $isZakatFund = $fund->category === \App\Models\Fund::CATEGORY_ZAKAT || $fund->code === 'ZKT';
    $isGrantFund = $fund->category === \App\Models\Fund::CATEGORY_GRANT || str_starts_with((string) $fund->code, 'GRT');
    $isParentDnr = ! $fund->parent_id && ($fund->code === 'DNR' || $fund->category === 'donor');

    $hideForInitialCategory = false;
    $allowSelection = true;
    if ($type->value === 'donation') {
        if ($selectedCategorySlug === 'new-frc-project-donation') {
            $hideForInitialCategory = ! ($isProjectChild && $fund->is_active);
            if ($hideForInitialCategory) {
                $allowSelection = false;
            }
        } elseif ($selectedCategorySlug === 'zakat') {
            $hideForInitialCategory = ! $isZakatFund;
            if ($hideForInitialCategory) {
                $allowSelection = false;
            }
        } elseif ($selectedCategorySlug === 'general-donation' || $selectedCategorySlug === '') {
            $hideForInitialCategory = $isZakatFund || $isGrantFund;
            if ($hideForInitialCategory) {
                $allowSelection = false;
            }
        }
    }
@endphp<option value="{{ $fund->id }}"@if($hideForInitialCategory) hidden disabled style="display: none;"@endif{{ $allowSelection && (string) $initialFundId === (string) $fund->id ? ' selected' : '' }}>{{ $fund->label() }}{{ $fund->isRestricted() ? ' (restricted)' : '' }}</option>@endforeach</select>@error('fund_id')<div class="invalid-feedback">{{ $message }}</div>@enderror <div class="form-text" id="transaction-fund-help">{{ $initialFundHelp }}</div></div><div class="col-md-6"><label class="form-label">{{ $isCashReceipt ? 'Receipt Reference' : ($isBankReceipt ? 'Bank Transaction / Deposit Reference' : 'Reference') }}</label><input class="form-control" name="reference_number" value="{{ old('reference_number') }}"></div><div class="col-12"><label class="form-label">{{ $isReceipt ? 'Description / Narration' : 'Narration' }}</label><textarea class="form-control" name="narration" required>{{ old('narration') }}</textarea></div><div class="col-md-6"><label class="form-label" id="transaction-attachment-label"><span id="transaction-attachment-label-text">{{ $isReceipt ? ($initialAttachmentRequired ? 'Supporting Document' : 'Supporting Document (optional)') : 'Attachment' }}</span><span class="text-danger ms-1 @if(! $initialAttachmentRequired) d-none @endif" id="transaction-attachment-required">*</span></label><input class="form-control @error('attachment') is-invalid @enderror" type="file" name="attachment" id="transaction-attachment" accept=".pdf,.jpg,.jpeg,.png,.webp">@error('attachment')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text" id="transaction-attachment-help">{{ $initialAttachmentHelp }}</div></div></div><div class="mt-4"><button class="btn btn-primary" value="Save draft" @disabled($ledgers->isEmpty())>{{ $isReceipt ? 'Save as Draft' : 'Save draft' }}</button> <a class="btn btn-outline-secondary" href="{{ route('transactions.index',$type->value) }}">Cancel</a></div></form></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const branch = document.getElementById('transaction-branch');
    const financialAccount = document.getElementById('transaction-financial-account');
    const financialAccountContainer = document.getElementById('financial-account-container');
    const expenseSettlement = document.getElementById('expense-settlement');
    const requiredAccountType = @json($requiredFinancialAccountType);

    if (!branch || !financialAccount) {
        return;
    }

    const refreshFinancialAccounts = () => {
        const selectedBranchId = branch.value;

        Array.from(financialAccount.options).forEach((option) => {
            if (!option.value) {
                return;
            }

            const available = selectedBranchId !== ''
                && (option.dataset.branchId === selectedBranchId || option.dataset.shared === '1')
                && (!requiredAccountType || option.dataset.accountType === requiredAccountType);
            option.hidden = !available;
            option.disabled = !available;
        });

        if (financialAccount.selectedOptions[0]?.disabled) {
            financialAccount.value = '';
        }
    };

    branch.addEventListener('change', refreshFinancialAccounts);
    if (expenseSettlement && financialAccountContainer) {
        const refreshExpenseSettlement = () => {
            const isAccrued = expenseSettlement.value === 'accrued';

            financialAccountContainer.hidden = isAccrued;
            financialAccount.disabled = isAccrued;
            financialAccount.required = !isAccrued;
            if (isAccrued) {
                financialAccount.value = '';
            }
        };

        expenseSettlement.addEventListener('change', refreshExpenseSettlement);
        refreshExpenseSettlement();
    }

    // Vendor payment: branch, vendor and outstanding follow the selected bill. The cash/bank
    // account and the amount are left for the user to choose.
    const bill = document.getElementById('vendor-payment-bill');
    if (bill) {
        const branchName = document.getElementById('vendor-payment-branch-name');
        const vendor = document.getElementById('vendor-payment-vendor');
        const vendorName = document.getElementById('vendor-payment-vendor-name');
        const outstanding = document.getElementById('vendor-payment-outstanding');
        const applyBill = () => {
            const option = bill.value ? bill.selectedOptions[0] : null;
            branch.value = option?.dataset.branchId ?? '';
            branchName.value = option?.dataset.branchName ?? '';
            vendor.value = option?.dataset.vendorId ?? '';
            vendorName.value = option?.dataset.vendorName ?? '';
            outstanding.textContent = option ? `Outstanding: PKR ${option.dataset.outstanding}` : '';
            refreshFinancialAccounts();
        };

        bill.addEventListener('change', applyBill);
        applyBill();
    }

    // Donation category default fund synchronization and policy guidance
    const donationCategory = document.getElementById('transaction-donation-category');
    const fundSelect = document.getElementById('transaction-fund');
    const fundRequiredStar = document.getElementById('transaction-fund-required');
    const fundHelp = document.getElementById('transaction-fund-help');
    const defaultOption = document.getElementById('transaction-fund-default-option');
    const attachmentLabelText = document.getElementById('transaction-attachment-label-text');
    const attachmentRequiredStar = document.getElementById('transaction-attachment-required');
    const attachmentHelp = document.getElementById('transaction-attachment-help');
    const isDonationType = @json($type->value === 'donation');
    const fundsData = @json($fundsMap);

    if (donationCategory && fundSelect) {
        const syncAttachmentGuidance = () => {
            if (!isDonationType || !attachmentHelp) {
                return;
            }
            const catOption = donationCategory.selectedOptions[0];
            const slug = catOption?.dataset.slug ?? '';
            const selectedFundId = fundSelect.value;
            const fundInfo = selectedFundId ? fundsData[selectedFundId] : null;

            const isDnrOrChild = fundInfo && (
                fundInfo.code === 'DNR' ||
                fundInfo.parentCode === 'DNR' ||
                fundInfo.category === 'donor'
            );

            if (slug === 'zakat' || slug.includes('zakat')) {
                if (attachmentRequiredStar) {
                    attachmentRequiredStar.classList.add('d-none');
                }
                if (attachmentLabelText) {
                    attachmentLabelText.textContent = 'Supporting Document (optional)';
                }
                attachmentHelp.textContent = 'Optional: Attach bank deposit slip, cheque image, or donor Zakat declaration if available.';
            } else if (slug === 'new-frc-project-donation' || slug.includes('project')) {
                if (attachmentRequiredStar) {
                    attachmentRequiredStar.classList.add('d-none');
                }
                if (attachmentLabelText) {
                    attachmentLabelText.textContent = 'Supporting Document (optional)';
                }
                attachmentHelp.textContent = 'Donor project confirmation required before processing. Attach donor project pledge/agreement, or confirm project attribution via child fund selection.';
            } else {
                // General Donation
                if (isDnrOrChild) {
                    if (attachmentRequiredStar) {
                        attachmentRequiredStar.classList.remove('d-none');
                    }
                    if (attachmentLabelText) {
                        attachmentLabelText.textContent = 'Supporting Document';
                    }
                    attachmentHelp.textContent = 'Mandatory: Attach donor-written restriction letter when allocating General Donations to a restricted project fund.';
                } else {
                    if (attachmentRequiredStar) {
                        attachmentRequiredStar.classList.add('d-none');
                    }
                    if (attachmentLabelText) {
                        attachmentLabelText.textContent = 'Supporting Document (optional)';
                    }
                    attachmentHelp.textContent = 'Optional: Attach donor acknowledgment, deposit slip, or payment confirmation if available.';
                }
            }
        };

        const filterFundOptionsForCategory = (slug) => {
            if (!isDonationType || !fundSelect) {
                return { activeProjectChildCount: 0 };
            }

            let activeProjectChildCount = 0;

            Array.from(fundSelect.options).forEach((option) => {
                if (!option.value) {
                    return;
                }

                const fund = fundsData[option.value];
                if (!fund) {
                    return;
                }

                const isProjectChild = Boolean(fund.isProjectChild && fund.isActive);
                const isZakatFund = fund.code === 'ZKT' || fund.category === 'zakat';
                const isGrantFund = fund.category === 'grant' || (fund.code && fund.code.startsWith('GRT'));

                let shouldShow = true;

                if (slug === 'new-frc-project-donation' || slug.includes('project')) {
                    // Show ONLY active project child funds whose parent is DNR
                    shouldShow = isProjectChild;
                    if (isProjectChild) {
                        activeProjectChildCount++;
                    }
                } else if (slug === 'zakat' || slug.includes('zakat')) {
                    // Show ONLY ZKT fund
                    shouldShow = isZakatFund;
                } else {
                    // General Donation: ZKT & GRT are not allowed; GEN, CAP, DNR and DNR child funds are allowed
                    shouldShow = !isZakatFund && !isGrantFund;
                }

                option.hidden = !shouldShow;
                option.disabled = !shouldShow;
                option.style.display = shouldShow ? '' : 'none';
            });

            return { activeProjectChildCount };
        };

        const syncDonationCategoryFund = (isUserChange = false) => {
            const option = donationCategory.selectedOptions[0];
            const slug = option?.dataset.slug ?? '';
            const defaultFundId = option?.dataset.fundId ?? '';
            const defaultFundCode = option?.dataset.fundCode ?? '';
            const defaultFundName = option?.dataset.fundName ?? '';

            const { activeProjectChildCount } = filterFundOptionsForCategory(slug);

            const currentFundId = fundSelect.value;
            const currentFund = currentFundId ? fundsData[currentFundId] : null;

            if (slug === 'zakat' || slug.includes('zakat')) {
                if (fundRequiredStar) fundRequiredStar.classList.remove('d-none');
                if (defaultOption) {
                    defaultOption.textContent = 'Select ZKT — Zakat / Charity Fund';
                    defaultOption.disabled = true;
                }
                if (fundHelp) {
                    fundHelp.textContent = 'Mandatory: Every Zakat donation must use the ZKT — Zakat / Charity Fund.';
                }
                // Preselect explicit ZKT
                if (defaultFundId) {
                    fundSelect.value = defaultFundId;
                } else {
                    const zktOpt = Array.from(fundSelect.options).find((o) => !o.disabled && (fundsData[o.value]?.code === 'ZKT' || fundsData[o.value]?.category === 'zakat'));
                    if (zktOpt) {
                        fundSelect.value = zktOpt.value;
                    }
                }
            } else if (slug === 'new-frc-project-donation' || slug.includes('project')) {
                if (defaultOption) defaultOption.disabled = false;
                if (fundRequiredStar) fundRequiredStar.classList.remove('d-none');

                if (activeProjectChildCount > 0) {
                    if (defaultOption) {
                        defaultOption.textContent = 'Select active project child fund under DNR';
                    }
                    if (fundHelp) {
                        fundHelp.textContent = 'Mandatory: Project donations must select a specific active child fund under DNR (parent DNR alone is not permitted).';
                    }
                } else {
                    if (defaultOption) {
                        defaultOption.textContent = 'No active project funds available — contact an authorized Fund Manager';
                    }
                    if (fundHelp) {
                        fundHelp.textContent = 'No active project funds available under DNR. Please contact an authorized Fund Manager to set up an active project fund before proceeding.';
                    }
                }

                // If currently selected fund is NOT an active project child fund under DNR, reset selection
                const isValidProjectChild = currentFund && currentFund.isProjectChild && currentFund.isActive;
                if (!isValidProjectChild) {
                    fundSelect.value = '';
                }
            } else {
                // General Donation
                if (defaultOption) defaultOption.disabled = false;
                if (fundRequiredStar) fundRequiredStar.classList.add('d-none');
                if (defaultOption) {
                    defaultOption.textContent = defaultFundId
                        ? `Use category default (${defaultFundCode} — ${defaultFundName})`
                        : 'No default fund (select a fund or leave unallocated)';
                }
                if (fundHelp) {
                    fundHelp.textContent = 'General donations default to GEN (General Fund). Allocating to a restricted fund requires an attached donor-written restriction.';
                }

                const isZakatOrGrant = currentFund && (currentFund.code === 'ZKT' || currentFund.category === 'zakat' || currentFund.category === 'grant' || currentFund.code?.startsWith('GRT'));
                if (isUserChange && (isZakatOrGrant || !currentFundId)) {
                    fundSelect.value = defaultFundId || '';
                }
            }

            if (fundSelect.selectedOptions[0]?.disabled) {
                fundSelect.value = (slug === 'general-donation' && defaultFundId) ? defaultFundId : '';
            }

            syncAttachmentGuidance();
        };

        donationCategory.addEventListener('change', () => syncDonationCategoryFund(true));
        fundSelect.addEventListener('change', syncAttachmentGuidance);

        // Synchronize on initial load
        syncDonationCategoryFund(false);
    }

    refreshFinancialAccounts();
});
</script>
@endsection
