@extends('layouts.app')
@section('title', 'Settle accrued payable')
@section('page-title', 'Settle accrued payable')
@section('page-description', 'Yeh payment sirf linked Accounts Payable liability ko clear karegi; expense dobara record nahin hogi.')
@section('content')
<div class="card"><div class="card-body">
    <dl class="row mb-4">
        <dt class="col-sm-3">Expense reference</dt><dd class="col-sm-9">{{ $obligation->source_reference ?: 'Expense #'.$obligation->source_expense_transaction_id }}</dd>
        <dt class="col-sm-3">Branch</dt><dd class="col-sm-9">{{ $obligation->branch->name }}</dd>
        <dt class="col-sm-3">Payable ledger</dt><dd class="col-sm-9">{{ $obligation->payableAccount->code }} — {{ $obligation->payableAccount->name }}</dd>
        <dt class="col-sm-3">Vendor</dt><dd class="col-sm-9">{{ $obligation->vendor?->name ?? 'Unassigned vendor' }}</dd>
        <dt class="col-sm-3">Outstanding</dt><dd class="col-sm-9 fw-bold">PKR {{ \App\Services\AccountingDecimal::format($outstanding, 2, 4) }}</dd>
    </dl>
    <form method="POST" action="{{ route('transactions.store') }}">@csrf
        <input type="hidden" name="branch_id" value="{{ $obligation->branch_id }}">
        <input type="hidden" name="payable_obligation_id" value="{{ $obligation->id }}">
        <input type="hidden" name="settlement_request_token" value="{{ $settlementRequestToken }}">
        <input type="hidden" name="contra_account_id" value="{{ $obligation->payable_account_id }}">
        <input type="hidden" name="party_name" value="{{ $obligation->vendor?->name ?? 'Unassigned vendor' }}">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="settlement-type">Payment method</label><select class="form-select" id="settlement-type" name="type" required><option value="cash_payment">Cash payment</option><option value="bank_payment">Bank payment</option></select></div>
            <div class="col-md-4"><label class="form-label" for="settlement-date">Payment date</label><input class="form-control" id="settlement-date" type="date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required></div>
            <div class="col-md-4"><label class="form-label" for="settlement-amount">Amount (PKR)</label><input class="form-control text-end" id="settlement-amount" name="amount" value="{{ old('amount', $outstanding) }}" inputmode="decimal" required></div>
            <div class="col-md-6"><label class="form-label" for="settlement-account">Cash / Bank account</label><select class="form-select @error('financial_account_id') is-invalid @enderror" id="settlement-account" name="financial_account_id" required><option value="">Select account</option>@foreach($financialAccounts as $account)<option value="{{ $account->id }}" data-type="{{ $account->type }}" @selected(old('financial_account_id') == $account->id)>{{ $account->name }} ({{ ucfirst($account->type) }})</option>@endforeach</select>@error('financial_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-6"><label class="form-label" for="settlement-reference">Payment reference</label><input class="form-control" id="settlement-reference" name="reference_number" value="{{ old('reference_number', $obligation->source_reference) }}"></div>
            <div class="col-12"><label class="form-label" for="settlement-narration">Narration</label><textarea class="form-control" id="settlement-narration" name="narration" required>{{ old('narration', 'Settlement of accrued payable '.$obligation->source_reference) }}</textarea></div>
        </div>
        <div class="mt-4"><button class="btn btn-primary">Create payment draft</button> <a class="btn btn-outline-secondary" href="{{ route('transactions.show', $obligation->sourceExpense) }}">Cancel</a></div>
    </form>
</div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('settlement-type');
    const account = document.getElementById('settlement-account');
    const refresh = () => {
        const expected = type.value === 'cash_payment' ? 'cash' : 'bank';
        Array.from(account.options).forEach((option) => {
            if (!option.value) return;
            const available = option.dataset.type === expected;
            option.hidden = !available;
            option.disabled = !available;
        });
        if (account.selectedOptions[0]?.dataset.type !== expected) account.value = '';
    };
    type.addEventListener('change', refresh);
    refresh();
});
</script>
@endsection
