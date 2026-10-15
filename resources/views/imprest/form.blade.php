@extends('layouts.app')
@php($editing = $float !== null)
@section('title', $editing ? 'Edit Imprest Float' : 'Set Up Imprest Float') @section('page-title', $editing ? 'Edit Imprest Float' : 'Set Up Imprest Float')
@section('page-description','The float amount is entered by you. The petty cash ledger must be the petty cash mapping ledger or a cash ledger of the branch; replenishments are drawn from a bank or cash account of the same branch.')
@section('content')
@if(! $editing)
    <div class="card mb-4"><div class="card-body"><form class="row g-3" method="GET" action="{{ route('imprest.create') }}">
        <div class="col-md-6"><label class="form-label" for="branch_picker">Branch</label><select class="form-select" id="branch_picker" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $option)<option value="{{ $option->id }}" @selected($branch?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary">Load ledgers</button></div>
    </form></div></div>
@endif

@if($branch)
<div class="card"><div class="card-body">
<form method="POST" action="{{ $editing ? route('imprest.update', $float) : route('imprest.store') }}">@csrf @if($editing) @method('PUT') @else <input type="hidden" name="branch_id" value="{{ $branch->id }}"> @endif
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Branch</label><input class="form-control" value="{{ $branch->name }}" disabled></div>
        <div class="col-md-6"><label class="form-label" for="account_id">Petty cash ledger</label>
            @if($editing)
                <input class="form-control" id="account_id" value="{{ $float->account->code }} — {{ $float->account->name }}" disabled><small class="text-muted">The ledger of a float is fixed. Set up a new float for a different ledger.</small>
            @else
                <select class="form-select" id="account_id" name="account_id" required><option value="">Select ledger</option>@foreach($ledgers as $ledger)<option value="{{ $ledger->id }}" @selected((int) old('account_id') === $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select>
                @if($ledgers->isEmpty())<small class="text-danger">No petty cash mapping ledger or active cash account exists for this branch.</small>@endif
            @endif
            @error('account_id')<div class="text-danger">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4"><label class="form-label" for="float_amount">Float amount ({{ $appSettings['currency_code'] }})</label><input class="form-control" id="float_amount" name="float_amount" inputmode="decimal" value="{{ old('float_amount', $float?->float_amount) }}" required>@error('float_amount')<div class="text-danger">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="source_financial_account_id">Replenishment source</label><select class="form-select" id="source_financial_account_id" name="source_financial_account_id" required><option value="">Select bank or cash account</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected((int) old('source_financial_account_id', $float?->source_financial_account_id) === $source->id)>{{ ucfirst($source->type) }}: {{ $source->name }} ({{ $source->account?->code }})</option>@endforeach</select>@error('source_financial_account_id')<div class="text-danger">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="custodian_name">Custodian</label><input class="form-control" id="custodian_name" name="custodian_name" maxlength="150" value="{{ old('custodian_name', $float?->custodian_name) }}" required>@error('custodian_name')<div class="text-danger">{{ $message }}</div>@enderror</div>
        @if($editing)
            <div class="col-12"><input type="hidden" name="is_active" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $float->is_active))><label class="form-check-label" for="is_active">Active</label></div></div>
        @endif
    </div>
    <div class="mt-4"><button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Set up float' }}</button> <a class="btn btn-outline-secondary" href="{{ $editing ? route('imprest.show', $float) : route('imprest.index') }}">Cancel</a></div>
</form>
</div></div>
@endif
@endsection
