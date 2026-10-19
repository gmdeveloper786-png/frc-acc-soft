@extends('layouts.app')
@php
    $editing = isset($financialAccount);
    $selectedType = old('type', $financialAccount->type ?? 'cash');
    $selectedLedger = (string) old('account_id', $financialAccount->account_id ?? '');
    $hasStoredAccountNumber = $editing && filled($financialAccount->account_number);
    $ledgerOptions = $ledgersByType->map(fn ($items) => $items->map(fn ($ledger) => [
        'id' => (string) $ledger->id,
        'label' => $ledger->code.' — '.$ledger->name,
    ])->values());
@endphp
@section('title', $editing ? 'Edit Financial Account' : 'Add Financial Account')
@section('page-title', $editing ? 'Edit Financial Account' : 'Add Financial Account')
@section('content')
<div class="card"><div class="card-body">
<form method="POST" action="{{ $editing ? route('financial-accounts.update', $financialAccount) : route('financial-accounts.store') }}">@csrf @if($editing)@method('PUT')<input type="hidden" name="keep_account_number" value="{{ $hasStoredAccountNumber ? 1 : 0 }}">@endif
<div class="row g-3">
<div class="col-md-6"><label class="form-label" for="financial-account-type">Type</label><select class="form-select @error('type') is-invalid @enderror" id="financial-account-type" name="type" required><option value="cash" @selected($selectedType === 'cash')>Cash</option><option value="bank" @selected($selectedType === 'bank')>Bank</option></select>@error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
<div class="col-md-6"><label class="form-label" for="financial-account-name">Account name</label><input class="form-control @error('name') is-invalid @enderror" id="financial-account-name" name="name" value="{{ old('name', $financialAccount->name ?? '') }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
<div class="col-md-6"><label class="form-label">Branch</label><select class="form-select @error('branch_id') is-invalid @enderror" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $financialAccount->branch_id ?? '') == $branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
<div class="col-md-6"><label class="form-label" for="financial-account-ledger">GL ledger</label><select class="form-select @error('account_id') is-invalid @enderror" id="financial-account-ledger" name="account_id" required><option value="">Select eligible ledger</option>@foreach($ledgersByType->get($selectedType, collect()) as $ledger)<option value="{{ $ledger->id }}" @selected($selectedLedger === (string) $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select>@error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror<small class="text-danger d-none" id="financial-account-ledger-empty">No active, posting-enabled GL ledger is configured for this type.</small></div>
<div class="col-md-6"><label class="form-label" for="financial-account-bank-name">Bank name <span class="text-danger {{ $selectedType === 'bank' ? '' : 'd-none' }}" data-bank-required>*</span></label><input class="form-control @error('bank_name') is-invalid @enderror" id="financial-account-bank-name" name="bank_name" value="{{ old('bank_name', $financialAccount->bank_name ?? '') }}" @required($selectedType === 'bank')>@error('bank_name')<div class="invalid-feedback" data-bank-error>{{ $message }}</div>@enderror</div>
<div class="col-md-6"><label class="form-label" for="financial-account-title">Account title <span class="text-danger {{ $selectedType === 'bank' ? '' : 'd-none' }}" data-bank-required>*</span></label><input class="form-control @error('account_title') is-invalid @enderror" id="financial-account-title" name="account_title" value="{{ old('account_title', $financialAccount->account_title ?? '') }}" @required($selectedType === 'bank')>@error('account_title')<div class="invalid-feedback" data-bank-error>{{ $message }}</div>@enderror</div>
<div class="col-md-6"><label class="form-label" for="financial-account-number">Account number / IBAN <span class="text-danger {{ $selectedType === 'bank' ? '' : 'd-none' }}" data-bank-required>*</span></label><input class="form-control @error('account_number') is-invalid @enderror" id="financial-account-number" name="account_number" value="{{ old('account_number') }}" placeholder="{{ $hasStoredAccountNumber ? 'Leave blank to keep '.$financialAccount->maskedNumber() : 'Domestic account number or IBAN' }}" @required($selectedType === 'bank' && ! $hasStoredAccountNumber)>@error('account_number')<div class="invalid-feedback" data-bank-error>{{ $message }}</div>@enderror</div>
<div class="col-md-3"><label class="form-label">Currency</label><input class="form-control" name="currency" value="{{ old('currency', $financialAccount->currency ?? 'PKR') }}" required></div>
<div class="col-md-3"><label class="form-label">Opening reference</label><input class="form-control" name="opening_reference" value="{{ old('opening_reference', $financialAccount->opening_reference ?? '') }}"></div>
</div>
<div class="mt-4"><button class="btn btn-primary">Save account</button> <a class="btn btn-outline-secondary" href="{{ route('financial-accounts.index') }}">Cancel</a></div>
</form></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('financial-account-type');
    const ledger = document.getElementById('financial-account-ledger');
    const empty = document.getElementById('financial-account-ledger-empty');
    const name = document.getElementById('financial-account-name');
    const bankFields = [document.getElementById('financial-account-bank-name'), document.getElementById('financial-account-title'), document.getElementById('financial-account-number')];
    const hasStoredAccountNumber = @json($hasStoredAccountNumber);
    const options = @json($ledgerOptions);

    const refreshLedgers = () => {
        const current = ledger.value;
        const eligible = options[type.value] || [];
        ledger.replaceChildren(new Option('Select eligible ledger', ''));
        eligible.forEach((item) => ledger.add(new Option(item.label, item.id, false, item.id === current)));
        empty.classList.toggle('d-none', eligible.length > 0);
    };

    const syncBankRequirements = () => {
        const isBank = type.value === 'bank';
        bankFields.forEach((field, index) => {
            field.required = isBank && (index !== 2 || !hasStoredAccountNumber);
            field.setAttribute('aria-required', field.required ? 'true' : 'false');
        });
        name.minLength = isBank ? 2 : 0;
        document.querySelectorAll('[data-bank-required]').forEach((marker) => marker.classList.toggle('d-none', !isBank));
        if (!isBank) {
            document.querySelectorAll('[data-bank-error]').forEach((error) => error.remove());
            bankFields.forEach((field) => field.classList.remove('is-invalid'));
        }
    };

    type.addEventListener('change', refreshLedgers);
    type.addEventListener('change', syncBankRequirements);
    syncBankRequirements();
    empty.classList.toggle('d-none', (options[type.value] || []).length > 0);
});
</script>
@endsection
