@php
    $currentBill = $purchaseBill ?? null;
    $editing = $currentBill !== null;
    $billLines = old('lines', $editing
        ? $currentBill->lines->map(fn ($line) => ['purchase_order_line_id' => $line->purchase_order_line_id, 'account_id' => $line->account_id, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_amount' => $line->unit_amount, 'remaining' => null])->all()
        : ($order
            ? ($order->isFullyBilled()
                ? $order->lines->map(fn ($line) => ['purchase_order_line_id' => $line->id, 'account_id' => $line->account_id, 'description' => $line->description, 'quantity' => '1.0000', 'unit_amount' => $line->unit_price, 'remaining' => '0.0000'])->values()->all()
                : $order->lines->map(fn ($line) => ['purchase_order_line_id' => $line->id, 'account_id' => $line->account_id, 'description' => $line->description, 'quantity' => $line->remainingQuantity(), 'unit_amount' => $line->unit_price, 'remaining' => $line->remainingQuantity()])->filter(fn ($line) => (float) $line['quantity'] > 0)->values()->all()
            )
            : [['purchase_order_line_id' => null, 'account_id' => null, 'description' => '', 'quantity' => '1.0000', 'unit_amount' => '', 'remaining' => null]]));
    if ($billLines === []) {
        $billLines = [['purchase_order_line_id' => null, 'account_id' => null, 'description' => '', 'quantity' => '1.0000', 'unit_amount' => '', 'remaining' => null]];
    }
@endphp
@extends('layouts.app')
@section('title', $editing ? 'Edit Purchase Bill' : 'New Purchase Bill')
@section('page-title', $editing ? 'Edit Purchase Bill '.$purchaseBill->bill_number : 'New Purchase Bill')
@section('page-description', $order ? 'Billing against '.$order->po_number.' - remaining '.$order->currency.' '.number_format((float) $order->remainingAmount(), 2).'.' : 'A bill may optionally reference an approved purchase order.')
@section('content')
@foreach(['purchase_order_id', 'fund_id', 'po_exception_reason', 'mapping', 'payable_account_id', 'lines', 'status'] as $field)
    @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror
@endforeach
@if($errors->has('lines.*'))
    <div class="alert alert-danger">
        @foreach(collect($errors->get('lines.*'))->flatten()->unique() as $message)
            <span class="d-block">{{ $message }}</span>
        @endforeach
    </div>
@endif
@if($order && $order->isFullyBilled())
    <div class="alert alert-warning mb-4" data-overbilling-notice>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="status-badge danger">PO Overbilling Exception</span>
            <strong>Purchase order {{ $order->po_number }} is fully billed.</strong>
        </div>
        <p class="mb-2 small">This purchase order has 0 remaining quantity and PKR 0.00 remaining value. Creating this bill will record a documented exception that fails closed to Director-level authorization. An ordinary approver cannot approve it.</p>
        <div class="small fw-semibold">A documented PO exception reason (minimum 10 characters) is mandatory.</div>
    </div>
@endif
<div class="card"><div class="card-body">
<form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('purchase-bills.update', $purchaseBill) : route('purchase-bills.store') }}" data-submit-once>
@csrf
@if($editing)
    @method('PUT')
    <input type="hidden" name="expected_updated_at" value="{{ $purchaseBill->updated_at->format('Y-m-d H:i:s.u') }}">
@endif
@if($order)
    <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">
    <input type="hidden" name="vendor_id" value="{{ $order->vendor_id }}">
    <input type="hidden" name="branch_id" value="{{ $order->branch_id }}">
@endif
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">Vendor</label>@if($order)<input class="form-control" value="{{ $order->vendor->name }}" disabled>@else<select class="form-select" name="vendor_id" required>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('vendor_id', $currentBill?->vendor_id) == $vendor->id)>{{ $vendor->name }}</option>@endforeach</select>@endif</div>
    <div class="col-md-4"><label class="form-label">Branch</label>@if($order)<input class="form-control" value="{{ $order->branch->name }}" disabled>@else<select class="form-select" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $currentBill?->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach</select>@endif</div>
    <div class="col-md-4"><label class="form-label">Bill number</label><input class="form-control @error('bill_number') is-invalid @enderror" name="bill_number" value="{{ old('bill_number', $currentBill?->bill_number) }}" required>@error('bill_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-4"><label class="form-label">Invoice date</label><input class="form-control" type="date" name="invoice_date" value="{{ old('invoice_date', $currentBill?->invoice_date?->toDateString()) }}" required></div>
    <div class="col-md-4"><label class="form-label">Due date</label><input class="form-control" type="date" name="due_date" value="{{ old('due_date', $currentBill?->due_date?->toDateString()) }}"></div>
    <div class="col-md-4"><label class="form-label">Purchase order</label>@if($order)<input class="form-control" value="{{ $order->po_number }}" disabled>@else<input class="form-control" name="purchase_order_reference" value="{{ old('purchase_order_reference', $currentBill?->purchase_order_reference) }}" placeholder="External reference (optional)"><small class="form-text">To bill against an approved PO, use "Create bill from PO" on the purchase order.</small>@endif</div>
    <div class="col-md-4">
        <label class="form-label">Fund</label>
        @php
            $defaultPBId = old('fund_id', $currentBill?->fund_id ?? $funds->firstWhere('code', 'GEN')?->id);
        @endphp
        <select class="form-select @error('fund_id') is-invalid @enderror" name="fund_id">
            <option value="">None / Unassigned (Auto GEN for expenses)</option>
            @foreach($funds as $fund)
                <option value="{{ $fund->id }}" @selected($defaultPBId == $fund->id)>{{ $fund->code }} — {{ $fund->name }} ({{ $fund->type }})</option>
            @endforeach
        </select>
        @error('fund_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4"><label class="form-label">Accounts payable ledger</label><select class="form-select" name="payable_account_id"><option value="">{{ $payableMapped ? 'Use GL mapping (Accounts Payable)' : 'GL mapping not configured - select a ledger' }}</option>@foreach($payableLedgers as $ledger)<option value="{{ $ledger->id }}" @selected(old('payable_account_id', $currentBill?->payable_account_id) == $ledger->id)>{{ $ledger->code }} - {{ $ledger->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label">Attachment</label><input class="form-control" type="file" name="attachment">@if($editing && $purchaseBill->attachment_path)<small class="form-text">A replacement attachment may be uploaded; the prior document remains preserved.</small>@endif</div>
    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description">{{ old('description', $currentBill?->description) }}</textarea></div>
    @if($order)
        <div class="col-12">
            <label class="form-label">
                PO exception reason
                @if($order->isFullyBilled())
                    <span class="text-danger fw-semibold">* (Mandatory: PO is fully billed)</span>
                @else
                    <span class="text-muted">(Required only if exceeding remaining PO value or quantity)</span>
                @endif
            </label>
            <textarea class="form-control" name="po_exception_reason" minlength="10" maxlength="2000" placeholder="{{ $order->isFullyBilled() ? 'Document the business justification for billing beyond the approved PO. Requires Director approval.' : 'Only required when this bill exceeds the remaining approved PO quantity or value. An exception needs separate authorized approval.' }}" {{ $order->isFullyBilled() ? 'required' : '' }}>{{ old('po_exception_reason', $currentBill?->po_exception_reason) }}</textarea>
        </div>
    @endif
</div>
<h2 class="h5 mt-4">Bill lines</h2>
@if($eligibleDebitLedgers->isEmpty())<div class="alert alert-warning">Koi approved Purchase Bill debit ledger configured nahi hai. Accountant pehle expense, inventory ya capital asset ledger ko eligible mark kare.</div>@endif
<div class="row g-2 mb-1 text-muted small fw-semibold d-none d-md-flex">
    <div class="col-md-4">Expense / asset ledger</div>
    <div class="col-md-3">Description</div>
    <div class="col-md-2 text-end">Quantity</div>
    <div class="col-md-2 text-end">Unit amount</div>
    <div class="col-md-1 text-center"></div>
</div>
<div id="billLines">
@foreach($billLines as $index => $line)
    <div class="row g-2 mb-2 align-items-center" data-bill-line>
        @if(!empty($line['purchase_order_line_id']))
            <input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $line['purchase_order_line_id'] }}" data-name="purchase_order_line_id">
        @endif
        <div class="col-md-4">
            <select class="form-select" name="lines[{{ $index }}][account_id]" data-name="account_id" aria-label="Expense or asset ledger" required>
                <option value="" disabled @selected(empty($line['account_id']))>Select expense / asset ledger</option>
                @foreach($eligibleDebitLedgers as $ledger)
                    <option value="{{ $ledger->id }}" @selected(($line['account_id'] ?? null) == $ledger->id)>{{ $ledger->code }} - {{ $ledger->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <input class="form-control" name="lines[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" placeholder="Description" data-name="description" aria-label="Description" required>
        </div>
        <div class="col-md-2">
            <input class="form-control text-end" name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" placeholder="Quantity" data-name="quantity" aria-label="Quantity" required>
            @php
                $poLine = (!empty($line['purchase_order_line_id']) && $order) ? $order->lines->firstWhere('id', $line['purchase_order_line_id']) : null;
                $remaining = $line['remaining'] ?? ($poLine ? $poLine->remainingQuantity() : null);
            @endphp
            @if($remaining !== null)
                <small class="form-text text-muted d-block">PO remaining {{ $remaining }}</small>
            @endif
        </div>
        <div class="col-md-2">
            <input class="form-control text-end" name="lines[{{ $index }}][unit_amount]" value="{{ $line['unit_amount'] ?? '' }}" placeholder="Unit amount" data-name="unit_amount" aria-label="Unit amount" required>
        </div>
        <div class="col-md-1 text-center">
            <button class="btn btn-sm btn-outline-danger w-100" type="button" data-remove-bill-line aria-label="Remove line" title="Remove line">×</button>
        </div>
    </div>
@endforeach
</div>
<button class="btn btn-sm btn-outline-primary" type="button" data-add-bill-line>+ Add line</button>
<div class="mt-4"><button class="btn btn-primary" @disabled($eligibleDebitLedgers->isEmpty())>{{ $editing ? 'Save draft changes' : 'Save draft' }}</button> <a class="btn btn-outline-primary" href="{{ $editing ? route('purchase-bills.show', $purchaseBill) : ($order ? route('purchase-orders.show', $order) : route('purchase-bills.index')) }}">Cancel</a></div>
</form></div></div>
<template id="billLineTemplate">
    <div class="row g-2 mb-2 align-items-center" data-bill-line>
        <div class="col-md-4">
            <select class="form-select" data-name="account_id" aria-label="Expense or asset ledger" required>
                <option value="" disabled selected>Select expense / asset ledger</option>
                @foreach($eligibleDebitLedgers as $ledger)
                    <option value="{{ $ledger->id }}">{{ $ledger->code }} - {{ $ledger->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <input class="form-control" data-name="description" placeholder="Description" aria-label="Description" required>
        </div>
        <div class="col-md-2">
            <input class="form-control text-end" data-name="quantity" value="1.0000" placeholder="Quantity" aria-label="Quantity" required>
        </div>
        <div class="col-md-2">
            <input class="form-control text-end" data-name="unit_amount" placeholder="Unit amount" aria-label="Unit amount" required>
        </div>
        <div class="col-md-1 text-center">
            <button class="btn btn-sm btn-outline-danger w-100" type="button" data-remove-bill-line aria-label="Remove line" title="Remove line">×</button>
        </div>
    </div>
</template>
<script>
function initBillLines() {
    const box = document.getElementById('billLines');
    const template = document.getElementById('billLineTemplate');
    const addBtn = document.querySelector('[data-add-bill-line]');
    if (! box || ! template) return;

    function updateRemoveButtons() {
        const rows = box.querySelectorAll('[data-bill-line]');
        rows.forEach(function (row) {
            const btn = row.querySelector('[data-remove-bill-line]');
            if (btn) {
                btn.disabled = rows.length <= 1;
                btn.classList.toggle('opacity-50', rows.length <= 1);
            }
        });
    }

    function reindex() {
        box.querySelectorAll('[data-bill-line]').forEach(function (row, index) {
            row.querySelectorAll('[data-name]').forEach(function (input) {
                input.name = `lines[${index}][${input.dataset.name}]`;
                if (input.type !== 'hidden') {
                    input.required = true;
                }
            });
        });
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            const node = template.content.cloneNode(true);
            box.append(node);
            reindex();
            updateRemoveButtons();
        });
    }

    box.addEventListener('click', function (event) {
        const removeBtn = event.target.closest('[data-remove-bill-line]');
        if (! removeBtn) return;
        const rows = box.querySelectorAll('[data-bill-line]');
        if (rows.length <= 1) return;
        const row = removeBtn.closest('[data-bill-line]');
        if (row) {
            row.remove();
            reindex();
            updateRemoveButtons();
        }
    });

    reindex();
    updateRemoveButtons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBillLines);
} else {
    initBillLines();
}
</script>
@endsection
