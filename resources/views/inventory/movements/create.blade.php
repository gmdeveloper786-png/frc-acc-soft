@php
    $screens = [
        'receipt' => ['title' => 'Receive Stock', 'route' => 'inventory.receive.store', 'button' => 'Record receipt', 'confirm' => 'Record this receipt? Stock movements cannot be edited or deleted afterwards.', 'description' => 'Receiving is an explicit action: approving a purchase order never adds stock. No accounting entry is posted.'],
        'issue' => ['title' => 'Issue Stock', 'route' => 'inventory.issue.store', 'button' => 'Issue stock', 'confirm' => 'Issue this stock? Stock movements cannot be edited or deleted afterwards.', 'description' => $allowsNegativeStock ? 'Settings currently allow an issue to take the balance below zero.' : 'An issue larger than the stock on hand at the location is rejected.'],
        'return' => ['title' => 'Record Stock Return', 'route' => 'inventory.return.store', 'button' => 'Record return', 'confirm' => 'Record this return? Stock movements cannot be edited or deleted afterwards.', 'description' => 'Stock coming back from a recipient increases the balance of the chosen location.'],
        'adjustment' => ['title' => 'Request Stock Adjustment', 'route' => 'inventory.adjust.store', 'button' => 'Submit for approval', 'confirm' => 'Submit this adjustment for approval?', 'description' => 'An adjustment stays pending and does not change stock until a different user approves it.'],
    ];
    $screen = $screens[$kind];
@endphp
@extends('layouts.app') @section('title',$screen['title']) @section('eyebrow','Inventory') @section('page-title',$screen['title'])
@section('page-description',$screen['description'])
@section('content')
@include('inventory._errors')
@if($items->isEmpty() || $locations->isEmpty())<div class="alert alert-warning">An active item and an active location in one of your branches are required before stock can be recorded.</div>@endif
<form method="POST" action="{{ route($screen['route']) }}" data-submit-once>@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Quantities only — up to 4 decimals</span><h2>Movement details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="item_id">Item</label><select class="form-select @error('item_id') is-invalid @enderror" id="item_id" name="item_id" required><option value="">Select item</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(old('item_id',request('item_id'))==$item->id)>{{ $item->item_code }} — {{ $item->name }} ({{ $item->unit_of_measure }})</option>@endforeach</select>@error('item_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6">@include('inventory.movements._location-select', ['field' => 'location_id', 'label' => 'Branch and location'])</div>
        @if($kind==='adjustment')
            <div class="col-md-4"><label class="form-label" for="direction">Adjustment</label><select class="form-select @error('direction') is-invalid @enderror" id="direction" name="direction" required><option value="">Select</option><option value="increase" @selected(old('direction')==='increase')>Increase stock</option><option value="decrease" @selected(old('direction')==='decrease')>Decrease stock</option></select>@error('direction')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @endif
        <div class="col-md-4"><label class="form-label" for="quantity">Quantity</label><input class="form-control @error('quantity') is-invalid @enderror" id="quantity" name="quantity" inputmode="decimal" value="{{ old('quantity') }}" required>@error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="transaction_date">Date</label><input class="form-control @error('transaction_date') is-invalid @enderror" id="transaction_date" type="date" name="transaction_date" max="{{ today()->toDateString() }}" value="{{ old('transaction_date',today()->toDateString()) }}" required>@error('transaction_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @if(in_array($kind,['issue','return'],true))
            <div class="col-md-4"><label class="form-label" for="issued_to">{{ $kind==='issue' ? 'Issued to' : 'Returned by' }}</label><input class="form-control @error('issued_to') is-invalid @enderror" id="issued_to" name="issued_to" maxlength="150" value="{{ old('issued_to') }}" placeholder="Person or department" @required($kind==='issue')>@error('issued_to')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @endif
        <div class="col-md-4"><label class="form-label" for="reference">Reference</label><input class="form-control @error('reference') is-invalid @enderror" id="reference" name="reference" maxlength="100" value="{{ old('reference') }}" placeholder="{{ $kind==='receipt' ? 'Delivery note or GRN number' : 'Requisition or slip number' }}">@error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @if($kind==='receipt')
            <div class="col-md-6"><label class="form-label" for="purchase_order_id">Purchase order (optional)</label><select class="form-select @error('purchase_order_id') is-invalid @enderror" id="purchase_order_id" name="purchase_order_id"><option value="">No purchase order</option>@foreach($purchaseOrders as $order)<option value="{{ $order->id }}" @selected(old('purchase_order_id')==$order->id)>{{ $order->po_number }} — {{ $order->vendor->name }} ({{ $order->branch->name }})</option>@endforeach</select>@error('purchase_order_id')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">Approved or issued orders of the same branch only. The link is for traceability.</div></div>
            <div class="col-md-6"><label class="form-label" for="purchase_order_line_id">Purchase order line (optional)</label><select class="form-select @error('purchase_order_line_id') is-invalid @enderror" id="purchase_order_line_id" name="purchase_order_line_id"><option value="">No specific line</option>@foreach($purchaseOrders as $order)<optgroup label="{{ $order->po_number }}">@foreach($order->lines as $line)<option value="{{ $line->id }}" @selected(old('purchase_order_line_id')==$line->id)>{{ $order->po_number }} · line {{ $line->line_number }} — {{ \Illuminate\Support\Str::limit($line->description, 60) }}</option>@endforeach</optgroup>@endforeach</select>@error('purchase_order_line_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @endif
        <div class="col-12"><label class="form-label" for="notes">{{ $kind==='adjustment' ? 'Reason for adjustment' : 'Notes' }}</label><textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="2" maxlength="2000" @required($kind==='adjustment')>{{ old('notes') }}</textarea>@error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" data-confirm="{{ $screen['confirm'] }}">{{ $screen['button'] }}</button><a class="btn btn-outline-primary" href="{{ route('inventory.balances.index') }}">Cancel</a></div>
</section>
</form>
@endsection
