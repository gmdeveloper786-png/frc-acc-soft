@extends('layouts.app') @section('title','New Purchase Bill') @section('page-title','New Purchase Bill')
@section('page-description', $order ? 'Billing against '.$order->po_number.' — remaining '.$order->currency.' '.number_format((float)$order->remainingAmount(),2).'.' : 'A bill may optionally reference an approved purchase order.')
@section('content')
@foreach(['purchase_order_id','po_exception_reason','mapping','payable_account_id','lines'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach
@php
    $billLines = $order
        ? $order->lines->map(fn ($line) => ['purchase_order_line_id' => $line->id, 'account_id' => $line->account_id, 'description' => $line->description, 'quantity' => $line->remainingQuantity(), 'unit_amount' => $line->unit_price, 'remaining' => $line->remainingQuantity()])->filter(fn ($line) => (float) $line['quantity'] > 0)->values()->all()
        : [['purchase_order_line_id' => null, 'account_id' => null, 'description' => '', 'quantity' => '1.0000', 'unit_amount' => '', 'remaining' => null]];
    if ($billLines === []) {
        $billLines = [['purchase_order_line_id' => null, 'account_id' => null, 'description' => '', 'quantity' => '1.0000', 'unit_amount' => '', 'remaining' => null]];
    }
@endphp
<div class="card"><div class="card-body"><form method="POST" enctype="multipart/form-data" action="{{ route('purchase-bills.store') }}" data-submit-once>@csrf
@if($order)<input type="hidden" name="purchase_order_id" value="{{ $order->id }}"><input type="hidden" name="vendor_id" value="{{ $order->vendor_id }}"><input type="hidden" name="branch_id" value="{{ $order->branch_id }}">@endif
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">Vendor</label>@if($order)<input class="form-control" value="{{ $order->vendor->name }}" disabled>@else<select class="form-select" name="vendor_id" required>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('vendor_id')==$vendor->id)>{{ $vendor->name }}</option>@endforeach</select>@endif</div>
    <div class="col-md-4"><label class="form-label">Branch</label>@if($order)<input class="form-control" value="{{ $order->branch->name }}" disabled>@else<select class="form-select" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>@endif</div>
    <div class="col-md-4"><label class="form-label">Bill number</label><input class="form-control @error('bill_number') is-invalid @enderror" name="bill_number" value="{{ old('bill_number') }}" required>@error('bill_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-4"><label class="form-label">Invoice date</label><input class="form-control" type="date" name="invoice_date" value="{{ old('invoice_date') }}" required></div>
    <div class="col-md-4"><label class="form-label">Due date</label><input class="form-control" type="date" name="due_date" value="{{ old('due_date') }}"></div>
    <div class="col-md-4"><label class="form-label">Purchase order</label>@if($order)<input class="form-control" value="{{ $order->po_number }}" disabled>@else<input class="form-control" name="purchase_order_reference" value="{{ old('purchase_order_reference') }}" placeholder="External reference (optional)"><small class="form-text">To bill against an approved PO, use "Create bill from PO" on the purchase order.</small>@endif</div>
    <div class="col-md-6"><label class="form-label">Accounts payable ledger</label><select class="form-select" name="payable_account_id"><option value="">{{ $payableMapped ? 'Use GL mapping (Accounts Payable)' : 'GL mapping not configured — select a ledger' }}</option>@foreach($ledgers->where('account_type',\App\Enums\AccountType::Liability) as $ledger)<option value="{{ $ledger->id }}" @selected(old('payable_account_id')==$ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label">Attachment</label><input class="form-control" type="file" name="attachment"></div>
    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description">{{ old('description') }}</textarea></div>
    @if($order)<div class="col-12"><label class="form-label">PO exception reason</label><textarea class="form-control" name="po_exception_reason" minlength="10" maxlength="2000" placeholder="Only required when this bill exceeds the remaining approved PO quantity or value. An exception needs separate authorized approval.">{{ old('po_exception_reason') }}</textarea></div>@endif
</div>
<h2 class="h5 mt-4">Bill lines</h2>
<div id="billLines">
@foreach($billLines as $index => $line)
    <div class="row g-2 mb-2">
        @if($line['purchase_order_line_id'])<input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $line['purchase_order_line_id'] }}">@endif
        <div class="col-md-4"><select class="form-select" name="lines[{{ $index }}][account_id]" required>@foreach($ledgers->whereIn('account_type',[\App\Enums\AccountType::Expense,\App\Enums\AccountType::Asset]) as $ledger)<option value="{{ $ledger->id }}" @selected($line['account_id']==$ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><input class="form-control" name="lines[{{ $index }}][description]" value="{{ $line['description'] }}" placeholder="Description" required></div>
        <div class="col-md-2"><input class="form-control text-end" name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] }}" required>@if($line['remaining']!==null)<small class="form-text">PO remaining {{ $line['remaining'] }}</small>@endif</div>
        <div class="col-md-3"><input class="form-control text-end" name="lines[{{ $index }}][unit_amount]" value="{{ $line['unit_amount'] }}" placeholder="Unit amount" required></div>
    </div>
@endforeach
</div>
<button class="btn btn-sm btn-outline-primary" type="button" data-add-bill-line>Add line</button>
<div class="mt-4"><button class="btn btn-primary" @disabled($ledgers->isEmpty())>Save draft</button> <a class="btn btn-outline-primary" href="{{ $order ? route('purchase-orders.show',$order) : route('purchase-bills.index') }}">Cancel</a></div>
</form></div></div>
<template id="billLineTemplate"><div class="row g-2 mb-2"><div class="col-md-4"><select class="form-select" data-name="account_id">@foreach($ledgers->whereIn('account_type',[\App\Enums\AccountType::Expense,\App\Enums\AccountType::Asset]) as $ledger)<option value="{{ $ledger->id }}">{{ $ledger->code }} — {{ $ledger->name }}</option>@endforeach</select></div><div class="col-md-3"><input class="form-control" data-name="description" placeholder="Description"></div><div class="col-md-2"><input class="form-control text-end" data-name="quantity" value="1.0000"></div><div class="col-md-3"><input class="form-control text-end" data-name="unit_amount" placeholder="Unit amount"></div></div></template>
<script>document.querySelector('[data-add-bill-line]').addEventListener('click',()=>{const box=document.querySelector('#billLines'),node=document.querySelector('#billLineTemplate').content.cloneNode(true),i=box.children.length;node.querySelectorAll('[data-name]').forEach(el=>{el.name=`lines[${i}][${el.dataset.name}]`;el.required=true});box.append(node)})</script>
@endsection
