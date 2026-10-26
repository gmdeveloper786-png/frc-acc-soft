@extends('layouts.app')
@section('title', 'Edit Account')
@section('eyebrow', 'Chart of Accounts')
@section('page-title', 'Edit '.$account->code)

@php
    $isLedger = $account->level->value === 'ledger';
    $debitType = in_array($account->account_type->value, ['asset', 'expense'], true);
    $isContra = ($account->normal_balance->value === 'debit') !== $debitType;
    // Same rules as Add Account: cash/bank only on debit Asset ledgers; Purchase Bill debit only on debit Asset or Expense ledgers.
    $cashBankApplies = $isLedger && $account->account_type->value === 'asset' && ! $isContra;
    $purchaseBillApplies = $isLedger && $debitType && ! $isContra;
    $typeLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
@endphp

@section('content')
<form class="surface-card" method="POST" action="{{ route('accounts.update', $account) }}">
    @csrf
    @method('PUT')
    <div class="card-heading"><h2>{{ $account->name }}</h2></div>
    <div class="form-stack">
        @error('account')<div class="alert alert-danger">{{ $message }}</div>@enderror

        <section>
            <h3 class="h6 mb-3">Account structure <small class="text-muted fw-normal">(fixed after creation)</small></h3>
            <dl class="definition-list">
                <div><dt>Account code</dt><dd><code>{{ $account->code }}</code></dd></div>
                <div><dt>Account type</dt><dd>{{ $typeLabels[$account->account_type->value] ?? ucfirst($account->account_type->value) }}</dd></div>
                <div><dt>Level</dt><dd>{{ str($account->level->value)->replace('_', ' ')->title() }}</dd></div>
                <div><dt>Parent account</dt><dd>{{ $account->parent ? $account->parent->code.' — '.$account->parent->name : 'None (account class)' }}</dd></div>
                <div><dt>Normal balance</dt><dd>{{ ucfirst($account->normal_balance->value) }}@if($isContra) <span class="status-badge neutral">Contra</span>@endif</dd></div>
                <div><dt>Financial statement</dt><dd>{{ $account->financial_statement->value === 'income_expenditure' ? 'Income & Expenditure' : 'Balance Sheet' }}</dd></div>
            </dl>
        </section>

        <div class="form-grid">
            <div class="form-span-2">
                <label class="form-label" for="account-name">Account name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="account-name" name="name" value="{{ old('name', $account->name) }}" maxlength="255" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2">
                <label class="form-label" for="account-description">Description (optional)</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="account-description" name="description" rows="3">{{ old('description', $account->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="account-sort-order">Sort order</label>
                <input type="number" min="0" class="form-control @error('sort_order') is-invalid @enderror" id="account-sort-order" name="sort_order" value="{{ old('sort_order', $account->sort_order) }}">
                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                @if($account->is_active && ! auth()->user()->can('accounts.deactivate'))
                    {{-- Without the deactivate permission an active account stays active. --}}
                    <input type="hidden" name="is_active" value="1">
                    <label class="choice-card"><input class="form-check-input" type="checkbox" checked disabled><span><strong>Active account</strong><small>You do not have permission to deactivate accounts.</small></span></label>
                @else
                    <input type="hidden" name="is_active" value="0">
                    <label class="choice-card"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((string) old('is_active', $account->is_active ? '1' : '0') === '1')><span><strong>Active account</strong><small>Deactivate child accounts first; activate parent accounts first.</small></span></label>
                @endif
                @error('is_active')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            @if($isLedger)
                <div>
                    <input type="hidden" name="is_postable" value="0">
                    <label class="choice-card"><input class="form-check-input" type="checkbox" name="is_postable" value="1" @checked((string) old('is_postable', $account->is_postable ? '1' : '0') === '1')><span><strong>Posting allowed</strong><small>Cannot be changed once the ledger has postings or opening balances.</small></span></label>
                    @error('is_postable')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                @if($cashBankApplies)
                    <div>
                        <label class="form-label" for="account-financial-type">Cash / bank eligibility</label>
                        <select class="form-select @error('financial_account_type') is-invalid @enderror" id="account-financial-type" name="financial_account_type">
                            <option value="">Not a cash or bank ledger</option>
                            <option value="cash" @selected(old('financial_account_type', $account->financial_account_type?->value) === 'cash')>Cash / Petty Cash</option>
                            <option value="bank" @selected(old('financial_account_type', $account->financial_account_type?->value) === 'bank')>Bank</option>
                        </select>
                        @error('financial_account_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text">Cannot be changed while the ledger backs a cash or bank account, or has postings.</small>
                    </div>
                @endif
                @if($purchaseBillApplies)
                    <div id="purchase-bill-option">
                        <input type="hidden" name="purchase_bill_debit_eligible" value="0">
                        <label class="choice-card"><input class="form-check-input" type="checkbox" id="account-purchase-bill" name="purchase_bill_debit_eligible" value="1" @checked((string) old('purchase_bill_debit_eligible', $account->purchase_bill_debit_eligible ? '1' : '0') === '1')><span><strong>Purchase Bill debit eligible</strong><small>Only approved Expense, inventory or capital Asset ledgers. Cash and bank ledgers are excluded.</small></span></label>
                        @error('purchase_bill_debit_eligible')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                @endif
                @if($isLedger && $account->account_type->value === 'expense' && ! $isContra)
                    <div>
                        <input type="hidden" name="direct_payment_eligible" value="0">
                        <label class="choice-card"><input class="form-check-input" type="checkbox" name="direct_payment_eligible" value="1" @checked((string) old('direct_payment_eligible', $account->direct_payment_eligible ? '1' : '0') === '1')><span><strong>Direct payment eligible</strong><small>Offered under Create Payment &gt; Direct expense. Leave off for payroll, depreciation, write-offs and other non-cash or workflow-owned expenses.</small></span></label>
                        @error('direct_payment_eligible')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                @endif
            @endif
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-primary" href="{{ route('accounts.show', $account) }}">Cancel</a>
            <button class="btn btn-primary">Save changes</button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // A cash or bank ledger is never Purchase Bill debit eligible.
    const financialType = document.getElementById('account-financial-type');
    const purchaseOption = document.getElementById('purchase-bill-option');
    if (!financialType || !purchaseOption) {
        return;
    }
    const refresh = () => {
        const isCashBank = financialType.value !== '';
        purchaseOption.hidden = isCashBank;
        purchaseOption.querySelectorAll('input').forEach((input) => { input.disabled = isCashBank; });
    };
    financialType.addEventListener('change', refresh);
    refresh();
});
</script>
@endsection
