@extends('layouts.app') @section('title','Create '.$type->label()) @section('page-title','Create '.$type->label())
@section('page-description','Posting tabhi available hogi jab approved GL mappings aur open period mojood hon.')
@php($requiredFinancialAccountType = match($type->value) { 'cash_receipt', 'cash_payment', 'petty_cash' => 'cash', 'bank_receipt', 'bank_payment' => 'bank', default => null })
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
<div class="col-md-4"><label class="form-label">Branch</label><select class="form-select" id="transaction-branch" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
@endif
<div class="col-md-4"><label class="form-label">Date</label><input class="form-control" type="date" name="transaction_date" value="{{ old('transaction_date',today()->toDateString()) }}" required></div><div class="col-md-4"><label class="form-label">Amount (PKR)</label><input class="form-control text-end" name="amount" value="{{ old('amount') }}" required></div>
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
<div class="col-md-6" id="financial-account-container" @if($type->value==='expense') hidden @endif><label class="form-label">Cash / Bank account</label><select class="form-select @error('financial_account_id') is-invalid @enderror" id="transaction-financial-account" name="financial_account_id" @if($type->value==='expense') disabled @endif><option value="">Select account</option>@foreach($financialAccounts as $fa)<option value="{{ $fa->id }}" data-branch-id="{{ $fa->branch_id }}" data-shared="{{ $fa->isShared() ? '1' : '0' }}" data-account-type="{{ $fa->type }}" @selected(old('financial_account_id')==$fa->id)>{{ $fa->name }} ({{ ucfirst($fa->type) }}{{ $fa->isShared() ? ' — all branches' : '' }})</option>@endforeach</select>@error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
@if(!in_array($type->value,['donation','vendor_payment']))<div class="col-md-6"><label class="form-label">Contra / expense account</label><select class="form-select" name="contra_account_id"><option value="">Use GL mapping default</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}">{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select></div>@endif
@if($type->value==='expense')<div class="col-md-6"><label class="form-label">Payable account (for accrued)</label><select class="form-select" name="payable_account_id"><option value="">Use Accounts Payable mapping</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}">{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select></div>@endif
@if($type->value==='donation')<div class="col-md-6"><label class="form-label">Donation category</label><select class="form-select" name="donation_category_id" required>@foreach($donationCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}{{ $cat->income_account_id?'':' (mapping required)' }}</option>@endforeach</select></div>@endif
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
<div class="col-md-6"><label class="form-label">Party / recipient / donor</label><input class="form-control" name="party_name" value="{{ old('party_name') }}"></div>@if($type->value==='vendor_payment')
    {{-- The vendor of a vendor payment is the vendor of its purchase bill; the server takes it from the bill. --}}
    <div class="col-md-6">
        <label class="form-label" for="vendor-payment-vendor-name">Vendor</label>
        <input class="form-control @error('vendor_id') is-invalid @enderror" id="vendor-payment-vendor-name" value="{{ $selectedBill?->vendor?->name }}" placeholder="Taken from the purchase bill" readonly>
        <input type="hidden" id="vendor-payment-vendor" name="vendor_id" value="{{ $selectedBill?->vendor_id }}">
        @error('vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@else
<div class="col-md-6"><label class="form-label">Vendor (optional)</label><select class="form-select" name="vendor_id"><option value="">None</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('vendor_id')==$vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
@endif
<div class="col-md-6"><label class="form-label">Service allocation (optional)</label><select class="form-select" name="service_id"><option value="">None</option>@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label">Fund (optional)</label><select class="form-select @error('fund_id') is-invalid @enderror" name="fund_id"><option value="">{{ $type->value==='donation' ? 'Use the donation category default fund' : 'No fund' }}</option>@foreach($funds as $fund)<option value="{{ $fund->id }}" @selected(old('fund_id')==$fund->id)>{{ $fund->label() }}{{ $fund->isRestricted() ? ' (restricted)' : '' }}</option>@endforeach</select>@error('fund_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><div class="col-md-6"><label class="form-label">Reference</label><input class="form-control" name="reference_number" value="{{ old('reference_number') }}"></div><div class="col-12"><label class="form-label">Narration</label><textarea class="form-control" name="narration" required>{{ old('narration') }}</textarea></div><div class="col-md-6"><label class="form-label">Attachment</label><input class="form-control" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp"></div></div><div class="mt-4"><button class="btn btn-primary" @disabled($ledgers->isEmpty())>Save draft</button> <a class="btn btn-outline-secondary" href="{{ route('transactions.index',$type->value) }}">Cancel</a></div></form></div></div>
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
    refreshFinancialAccounts();
});
</script>
@endsection
