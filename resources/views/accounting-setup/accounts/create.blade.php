@extends('layouts.app')
@section('title', 'Add Account')
@section('eyebrow', 'Chart of Accounts')
@section('page-title', 'Add Account')
@section('page-description', 'Choose the account type, level and parent. The account code and accounting classification are assigned by the system.')

@php
    $typeLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
    $levelLabels = ['group' => 'Group', 'sub_group' => 'Sub Group', 'ledger' => 'Ledger'];
    $childLevel = ['class' => 'group', 'group' => 'sub_group', 'sub_group' => 'ledger'];
    $oldType = old('account_type', $selectedParent?->account_type?->value);
    $oldLevel = old('level', $selectedParent ? $childLevel[$selectedParent->level->value] : null);
    $oldParent = (string) old('parent_id', $selectedParent?->id);
@endphp

@section('content')
<form class="surface-card" method="POST" action="{{ route('accounts.store') }}" id="add-account-form">
    @csrf
    <div class="card-heading"><h2>Account details</h2></div>
    <div class="form-stack">
        @if($errors->any())
            <div class="alert alert-danger">Please correct the highlighted fields.</div>
        @endif
        <div class="form-grid">
            <div>
                <label class="form-label" for="account-type">1. Account type</label>
                <select class="form-select @error('account_type') is-invalid @enderror" id="account-type" name="account_type" required>
                    <option value="">Select account type</option>
                    @foreach($classes as $class)
                        <option value="{{ $class->account_type->value }}" @selected($oldType === $class->account_type->value)>{{ $class->code }} — {{ $typeLabels[$class->account_type->value] ?? $class->name }}</option>
                    @endforeach
                </select>
                @error('account_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="account-level">2. Level</label>
                <select class="form-select @error('level') is-invalid @enderror" id="account-level" name="level" required>
                    <option value="">Select level</option>
                    @foreach($levelLabels as $value => $label)
                        <option value="{{ $value }}" @selected($oldLevel === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text">Classes are fixed. Group goes under a Class, Sub Group under a Group, Ledger under a Sub Group.</small>
            </div>
            <div class="form-span-2">
                <label class="form-label" for="account-parent">3. Parent account</label>
                <select class="form-select @error('parent_id') is-invalid @enderror" id="account-parent" name="parent_id" required>
                    <option value="">Select type and level first</option>
                    @foreach($parents as $parent)
                        <option value="{{ $parent->id }}" data-type="{{ $parent->account_type->value }}" data-level="{{ $parent->level->value }}"
                            data-next-code="{{ $parent->next_code_preview }}" data-contra="{{ $parent->contra_children ? '1' : '0' }}"
                            @selected($oldParent === (string) $parent->id)>{{ $parent->code }} — {{ $parent->name }}</option>
                    @endforeach
                </select>
                @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2">
                <label class="form-label" for="account-name">4. Account name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="account-name" name="name" value="{{ old('name') }}" maxlength="255" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div>
                <label class="form-label" for="account-code-preview">Account code (assigned on save)</label>
                <input class="form-control @error('code') is-invalid @enderror" id="account-code-preview" value="" placeholder="Select a parent" readonly>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text">Preview only. The final code is allocated by the server when the account is saved.</small>
            </div>
            <div>
                <label class="form-label" for="account-classification">Classification (automatic)</label>
                <input class="form-control" id="account-classification" value="" readonly>
                <small class="form-text">Statement and normal balance follow the account type.</small>
            </div>

            <div data-ledger-only>
                <input type="hidden" name="is_postable" value="0">
                <label class="choice-card"><input class="form-check-input" type="checkbox" name="is_postable" value="1" @checked(old('is_postable', '1') === '1')><span><strong>Posting allowed</strong><small>Ledgers receive journal postings. Groups and Sub Groups never do.</small></span></label>
                @error('is_postable')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div data-ledger-only>
                <input type="hidden" name="is_contra" value="0">
                <label class="choice-card"><input class="form-check-input" type="checkbox" id="account-contra" name="is_contra" value="1" @checked(old('is_contra') === '1')><span><strong>Contra account</strong><small>Opposite normal balance, e.g. accumulated depreciation or discounts allowed.</small></span></label>
            </div>
            <div data-ledger-only data-cash-bank>
                <label class="form-label" for="account-financial-type">Cash / bank eligibility</label>
                <select class="form-select @error('financial_account_type') is-invalid @enderror" id="account-financial-type" name="financial_account_type">
                    <option value="">Not a cash or bank ledger</option>
                    <option value="cash" @selected(old('financial_account_type') === 'cash')>Cash / Petty Cash</option>
                    <option value="bank" @selected(old('financial_account_type') === 'bank')>Bank</option>
                </select>
                @error('financial_account_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text">Only for Asset ledgers that will back a cash or bank account.</small>
            </div>
            <div data-ledger-only data-purchase-bill>
                <input type="hidden" name="purchase_bill_debit_eligible" value="0">
                <label class="choice-card"><input class="form-check-input" type="checkbox" id="account-purchase-bill" name="purchase_bill_debit_eligible" value="1" @checked(old('purchase_bill_debit_eligible') === '1')><span><strong>Purchase Bill debit eligible</strong><small>Off by default. Only approved Expense, inventory or capital Asset ledgers; never cash, bank or contra ledgers.</small></span></label>
                @error('purchase_bill_debit_eligible')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2">
                <label class="form-label" for="account-description">Description (optional)</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="account-description" name="description" rows="3">{{ old('description') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-primary" href="{{ route('accounts.index') }}">Cancel</a>
            <button class="btn btn-primary">Save account</button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('account-type');
    const level = document.getElementById('account-level');
    const parent = document.getElementById('account-parent');
    const codePreview = document.getElementById('account-code-preview');
    const classification = document.getElementById('account-classification');
    const contra = document.getElementById('account-contra');
    const financialType = document.getElementById('account-financial-type');
    const purchaseBill = document.getElementById('account-purchase-bill');
    const parentLevel = { group: 'class', sub_group: 'group', ledger: 'sub_group' };
    const debitTypes = ['asset', 'expense'];
    const statement = { asset: 'Balance Sheet', liability: 'Balance Sheet', equity: 'Balance Sheet', income: 'Income & Expenditure', expense: 'Income & Expenditure' };
    const show = (selector, visible) => document.querySelectorAll(selector).forEach((element) => {
        element.hidden = !visible;
        element.querySelectorAll('input, select').forEach((input) => { input.disabled = !visible; });
    });

    const refresh = (resetInvalid) => {
        // Parents: same type, one level up, active (inactive accounts are not listed at all).
        Array.from(parent.options).forEach((option) => {
            if (!option.value) {
                option.textContent = type.value && level.value ? 'Select parent account' : 'Select type and level first';
                return;
            }
            const valid = option.dataset.type === type.value && option.dataset.level === parentLevel[level.value];
            option.hidden = !valid;
            option.disabled = !valid;
        });
        if (resetInvalid && parent.selectedOptions[0]?.disabled) {
            parent.value = '';
        }

        const selected = parent.value ? parent.selectedOptions[0] : null;
        const isLedger = level.value === 'ledger';
        if (resetInvalid === 'parent' && selected && isLedger) {
            contra.checked = selected.dataset.contra === '1';
        }
        codePreview.value = selected ? (selected.dataset.nextCode || 'No code available under this parent') : '';

        const isContra = isLedger && contra.checked;
        const debit = debitTypes.includes(type.value) !== isContra;
        classification.value = type.value ? `${statement[type.value]} · ${debit ? 'Debit' : 'Credit'} normal balance` : '';

        show('[data-ledger-only]', isLedger);
        // Cash/bank only on debit Asset ledgers; Purchase Bill debit only on debit Expense or Asset ledgers that are not cash/bank.
        const cashBank = isLedger && type.value === 'asset' && !isContra;
        show('[data-cash-bank]', cashBank);
        if (!cashBank) {
            financialType.value = '';
        }
        const purchase = isLedger && debitTypes.includes(type.value) && !isContra && !financialType.value;
        show('[data-purchase-bill]', purchase);
        if (!purchase) {
            purchaseBill.checked = false;
        }
    };

    type.addEventListener('change', () => refresh(true));
    level.addEventListener('change', () => refresh(true));
    parent.addEventListener('change', () => refresh('parent'));
    contra.addEventListener('change', () => refresh(false));
    financialType.addEventListener('change', () => refresh(false));
    refresh(true);
});
</script>
@endsection
