@extends('layouts.app') @section('title','Branch Transfer') @section('eyebrow','Inventory') @section('page-title','Branch Transfer')
@section('page-description','Moves stock between two locations in one step. The source cannot go below zero and the organisation total does not change.')
@section('content')
@include('inventory._errors')
@if($items->isEmpty() || $locations->flatten(1)->count() < 2)<div class="alert alert-warning">A transfer needs an active item and two active locations in branches you can access.</div>@endif
<form method="POST" action="{{ route('inventory.transfer.store') }}" data-submit-once>@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Both legs are recorded together</span><h2>Transfer details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-12"><label class="form-label" for="item_id">Item</label><select class="form-select @error('item_id') is-invalid @enderror" id="item_id" name="item_id" required><option value="">Select item</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(old('item_id',request('item_id'))==$item->id)>{{ $item->item_code }} — {{ $item->name }} ({{ $item->unit_of_measure }})</option>@endforeach</select>@error('item_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6">@include('inventory.movements._location-select', ['field' => 'from_location_id', 'label' => 'From (source)'])</div>
        <div class="col-md-6">@include('inventory.movements._location-select', ['field' => 'to_location_id', 'label' => 'To (destination)'])</div>
        <div class="col-md-4"><label class="form-label" for="quantity">Quantity</label><input class="form-control @error('quantity') is-invalid @enderror" id="quantity" name="quantity" inputmode="decimal" value="{{ old('quantity') }}" required>@error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="transaction_date">Date</label><input class="form-control @error('transaction_date') is-invalid @enderror" id="transaction_date" type="date" name="transaction_date" max="{{ today()->toDateString() }}" value="{{ old('transaction_date',today()->toDateString()) }}" required>@error('transaction_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="reference">Reference</label><input class="form-control @error('reference') is-invalid @enderror" id="reference" name="reference" maxlength="100" value="{{ old('reference') }}" placeholder="Transfer note number">@error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>@error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" data-confirm="Transfer this stock? Stock movements cannot be edited or deleted afterwards.">Transfer stock</button><a class="btn btn-outline-primary" href="{{ route('inventory.balances.index') }}">Cancel</a></div>
</section>
</form>
@endsection
