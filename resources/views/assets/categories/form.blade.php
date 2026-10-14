@php
    $category = $category ?? null;
    $accountFields = [
        'asset_account_id' => ['Asset ledger', $assetLedgers, 'Asset ledger with a debit normal balance.'],
        'accumulated_depreciation_account_id' => ['Accumulated depreciation ledger', $contraLedgers, 'Contra-asset: an asset ledger with a credit normal balance.'],
        'depreciation_expense_account_id' => ['Depreciation expense ledger', $expenseLedgers, 'Expense ledger debited by each depreciation run.'],
    ];
@endphp
@extends('layouts.app') @section('title', $category ? 'Edit Asset Category' : 'New Asset Category') @section('eyebrow','Fixed Assets') @section('page-title', $category ? 'Edit Asset Category '.$category->code : 'New Asset Category')
@section('page-description','Accounts are copied to each asset at registration. Changing a category later affects only assets registered afterwards.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('asset-categories.index') }}">Back</a>@endsection
@section('content')
<form method="POST" action="{{ $category ? route('asset-categories.update',$category) : route('asset-categories.store') }}" data-submit-once>@csrf @if($category) @method('PUT') @endif
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Configurable record</span><h2>Category details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label" for="code">Code</label><input class="form-control @error('code') is-invalid @enderror" id="code" name="code" maxlength="30" value="{{ old('code',$category?->code) }}" required>@error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="150" value="{{ old('name',$category?->name) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label" for="default_useful_life_months">Default useful life (months)</label><input class="form-control @error('default_useful_life_months') is-invalid @enderror" id="default_useful_life_months" type="number" min="1" max="1200" step="1" name="default_useful_life_months" value="{{ old('default_useful_life_months',$category?->default_useful_life_months) }}" required>@error('default_useful_life_months')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @foreach($accountFields as $field => [$label, $ledgers, $hint])
            <div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ $label }}</label>
                <select class="form-select @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" required><option value="">Select ledger</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}" @selected(old($field,$category?->{$field})==$ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select>
                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                <span class="table-subtext">{{ $hint }}</span>
            </div>
        @endforeach
        <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="2" maxlength="2000">{{ old('description',$category?->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active',$category?->is_active ?? true))><label class="form-check-label" for="is_active">Active — available when registering new assets</label></div></div>
    </div>
    @if($contraLedgers->isEmpty())<div class="alert alert-warning mt-3 mb-0">No contra-asset ledger exists yet. Create an asset ledger with a credit normal balance in the Chart of Accounts for accumulated depreciation.</div>@endif
    <div class="form-actions"><button class="btn btn-primary">{{ $category ? 'Save changes' : 'Create category' }}</button><a class="btn btn-outline-primary" href="{{ route('asset-categories.index') }}">Cancel</a></div>
</section>
</form>
@endsection
