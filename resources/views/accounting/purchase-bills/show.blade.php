@extends('layouts.app')
@section('title', 'Purchase Bill')
@section('page-title', 'Purchase Bill '.$purchaseBill->bill_number)
@section('content')
<div class="row g-4">
    <div class="col-lg-8"><div class="card"><div class="card-body">
        <p><strong>{{ $purchaseBill->vendor->name }}</strong> - {{ $purchaseBill->branch->name }} - {{ $purchaseBill->invoice_date->format('d M Y') }}</p>
        @if($purchaseBill->purchaseOrder)
            <p>Purchase order: @can('purchase_orders.view')<a href="{{ route('purchase-orders.show', $purchaseBill->purchaseOrder) }}">{{ $purchaseBill->purchaseOrder->po_number }}</a>@else{{ $purchaseBill->purchaseOrder->po_number }}@endcan @if($purchaseBill->po_exception_status)<span class="status-badge {{ $purchaseBill->po_exception_status === 'approved' ? 'success' : 'pending' }}">PO exception {{ $purchaseBill->po_exception_status }}</span>@endif</p>
            @if($purchaseBill->po_exception_reason)<div class="alert alert-warning"><strong>Exceeds purchase order - documented exception:</strong> {{ $purchaseBill->po_exception_reason }}@if($purchaseBill->po_exception_status === 'pending') <span class="d-block">Approval requires a user authorized for PO billing exceptions.</span>@endif</div>@endif
        @endif
        @error('approval')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('status')<div class="alert alert-danger">{{ $message }}</div>@enderror
        <div class="table-responsive"><table class="table"><thead><tr><th>Account</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr></thead><tbody>
        @foreach($purchaseBill->lines as $line)<tr><td>{{ $line->account->code }} - {{ $line->account->name }}</td><td>{{ $line->description }}</td><td class="text-end">{{ $line->quantity }}</td><td class="text-end">{{ number_format((float) $line->unit_amount, 2) }}</td><td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td></tr>@endforeach
        </tbody><tfoot><tr><th colspan="4" class="text-end">Total</th><th class="text-end">PKR {{ number_format((float) $purchaseBill->total_amount, 2) }}</th></tr><tr><th colspan="4" class="text-end">Outstanding</th><th class="text-end">PKR {{ number_format((float) $purchaseBill->outstandingAmount(), 2) }}</th></tr></tfoot></table></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5">Status: {{ $purchaseBill->status->value }}</h2><div class="d-grid gap-2">
        @if($purchaseBill->status->value === 'draft')
            @can('purchase_bills.update')<a class="btn btn-outline-primary w-100" href="{{ route('purchase-bills.edit', $purchaseBill) }}">Edit draft</a>@endcan
            @can('purchase_bills.submit')<form method="POST" action="{{ route('purchase-bills.submit', $purchaseBill) }}">@csrf<button class="btn btn-primary w-100">Submit</button></form>@endcan
        @endif
        @if($purchaseBill->status->value === 'submitted')
            @if($canApprove)
                <form method="POST" action="{{ route('purchase-bills.approve', $purchaseBill) }}">
                    @csrf
                    <button class="btn btn-success w-100" data-confirm="Approve bill?">Approve</button>
                </form>
            @endif
            @if($canReturn)
                <form method="POST" action="{{ route('purchase-bills.reject', $purchaseBill) }}">
                    @csrf
                    <textarea class="form-control mb-2" name="reason" placeholder="Rejection reason" required></textarea>
                    <button class="btn btn-outline-danger w-100">Return for correction</button>
                </form>
            @endif
            @if($isPreparer && auth()->user()->can('purchase_bills.approve'))
                <p class="text-muted small mb-0">You prepared this bill, so an independent approver must approve or return it.</p>
            @endif
        @endif
        @if($purchaseBill->status->value === 'approved')
            @can('purchase_bills.post')<form method="POST" action="{{ route('purchase-bills.post', $purchaseBill) }}">@csrf<button class="btn btn-primary w-100" data-confirm="Post payable journal?">Post</button></form>@endcan
        @endif
        @if($purchaseBill->status->value === 'posted' && (float) $purchaseBill->outstandingAmount() > 0)
            @can('vendor_payments.create')<a class="btn btn-outline-primary" href="{{ route('transactions.create', ['type' => 'vendor_payment', 'bill' => $purchaseBill->id]) }}">Record vendor payment</a>@endcan
        @endif
    </div></div></div></div>
</div>
@endsection
