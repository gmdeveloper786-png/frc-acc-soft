@extends('layouts.app') @section('title','Purchase Order '.$purchaseOrder->po_number) @section('eyebrow','Procurement') @section('page-title','Purchase Order '.$purchaseOrder->po_number)
@section('page-description',$purchaseOrder->vendor->name.' • '.$purchaseOrder->branch->name.' • '.$purchaseOrder->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @can('purchase_orders.update') @if($purchaseOrder->status->isEditable())<a class="btn btn-outline-primary" href="{{ route('purchase-orders.edit',$purchaseOrder) }}">Edit draft</a>@endif @endcan
    @can('purchase_bills.create') @if($purchaseOrder->status->acceptsBills())<a class="btn btn-primary" href="{{ route('purchase-bills.create',['purchase_order'=>$purchaseOrder->id]) }}">Create bill from PO</a>@endif @endcan
    <a class="btn btn-outline-primary" href="{{ route('purchase-orders.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $status = $purchaseOrder->status;
    $user = auth()->user();
    $isMaker = in_array($user->id, [$purchaseOrder->created_by, $purchaseOrder->submitted_by], true);
    $currency = $purchaseOrder->currency;
@endphp
@foreach(['status','approval','vendor_id','reason'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<div class="kpi-grid">
    <x-kpi-card label="Order total" value="{{ $currency }} {{ number_format((float)$purchaseOrder->total_amount,2) }}" icon="Σ" tone="navy" status="{{ $status->label() }}" description="Subtotal {{ number_format((float)$purchaseOrder->subtotal,2) }} · discount {{ number_format((float)$purchaseOrder->discount_total,2) }} · tax {{ number_format((float)$purchaseOrder->tax_total,2) }}"/>
    <x-kpi-card label="Billed" value="{{ $currency }} {{ number_format((float)$purchaseOrder->billedAmount(),2) }}" icon="B" tone="teal" status="Linked bills" description="Rejected and reversed bills are not counted"/>
    <x-kpi-card label="Remaining" value="{{ $currency }} {{ number_format((float)$purchaseOrder->remainingAmount(),2) }}" icon="R" tone="amber" status="Unbilled" description="Order total less billed value"/>
    <x-kpi-card label="Approval level" value="{{ $approvalTier==='director' ? 'Director' : 'Standard' }}" icon="A" tone="rose" status="From settings" description="{{ $approvalTier==='director' ? 'Above the configured PO threshold' : 'At or below the configured PO threshold' }}"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Procurement commitment — no General Ledger posting</span><h2>Order details</h2></div><span class="status-badge {{ $status->badge() }}">{{ $status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>PO date</small><strong class="d-block">{{ $purchaseOrder->po_date->format('d M Y') }}</strong></div>
        <div class="col-md-3"><small>Expected delivery</small><strong class="d-block">{{ $purchaseOrder->expected_delivery_date?->format('d M Y') ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Reference</small><strong class="d-block">{{ $purchaseOrder->reference ?: '—' }}</strong></div>
        <div class="col-md-3"><small>Created by</small><strong class="d-block">{{ $purchaseOrder->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approved by</small><strong class="d-block">{{ $purchaseOrder->approver?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approval date</small><strong class="d-block">{{ $purchaseOrder->approved_at?->format('d M Y H:i') ?? '—' }}</strong></div>
        <div class="col-md-6"><small>Description</small><p class="mb-0">{{ $purchaseOrder->description ?: '—' }}</p></div>
        @if($purchaseOrder->rejection_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned for correction:</strong> {{ $purchaseOrder->rejection_reason }}</div></div>@endif
        @if($purchaseOrder->closure_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>{{ $status->label() }} note:</strong> {{ $purchaseOrder->closure_reason }}</div></div>@endif
    </div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Quantities and values</span><h2>Order lines and billing reconciliation</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>#</th><th>Item / description</th><th class="text-end">Qty</th><th>Unit</th><th class="text-end">Unit price</th><th class="text-end">Discount</th><th class="text-end">Tax</th><th class="text-end">Line total</th><th class="text-end">Billed qty</th><th class="text-end">Remaining qty</th></tr></thead><tbody>
    @foreach($purchaseOrder->lines as $line)
        <tr><td>{{ $line->line_number }}</td><td>{{ $line->description }}@if($line->account || $line->service)<span class="table-subtext">{{ $line->account ? $line->account->code.' — '.$line->account->name : '' }}{{ $line->account && $line->service ? ' · ' : '' }}{{ $line->service?->name }}</span>@endif</td><td class="text-end font-monospace">{{ $line->quantity }}</td><td>{{ $line->unit ?: '—' }}</td><td class="text-end font-monospace">{{ number_format((float)$line->unit_price,2) }}</td><td class="text-end font-monospace">{{ number_format((float)$line->discount_amount,2) }}</td><td class="text-end font-monospace">{{ number_format((float)$line->tax_amount,2) }}</td><td class="text-end font-monospace">{{ number_format((float)$line->line_total,2) }}</td><td class="text-end font-monospace">{{ $line->billedQuantity() }}</td><td class="text-end font-monospace">{{ $line->remainingQuantity() }}</td></tr>
    @endforeach
    </tbody><tfoot><tr><th colspan="7" class="text-end">Order total</th><th class="text-end font-monospace">{{ $currency }} {{ number_format((float)$purchaseOrder->total_amount,2) }}</th><th colspan="2"></th></tr></tfoot></table></div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Payables are recognised here, once per bill</span><h2>Linked purchase bills</h2></div><span class="status-badge neutral">{{ $purchaseOrder->bills->count() }} bills</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Bill number</th><th>Invoice date</th><th>Status</th><th>PO exception</th><th class="text-end">Bill total</th><th></th></tr></thead><tbody>
    @forelse($purchaseOrder->bills as $bill)
        <tr><td><code>{{ $bill->bill_number }}</code></td><td>{{ $bill->invoice_date->format('d M Y') }}</td><td><span class="status-badge neutral">{{ ucfirst($bill->status->value) }}</span></td><td>@if($bill->po_exception_status)<span class="status-badge {{ $bill->po_exception_status==='approved'?'success':'pending' }}">Exception {{ $bill->po_exception_status }}</span><span class="table-subtext">{{ $bill->po_exception_reason }}</span>@else Within PO @endif</td><td class="text-end font-monospace">{{ number_format((float)$bill->total_amount,2) }}</td><td>@can('purchase_bills.view')<a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-bills.show',$bill) }}">View</a>@endcan</td></tr>
    @empty
        <tr><td colspan="6"><x-empty-state title="No bills linked yet" description="Bills can reference this order once it is approved. Receiving goods and billing are separate events from approval." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Workflow</span><h2>Actions</h2></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        @if($status->isEditable()) @can('purchase_orders.submit')
            <form method="POST" action="{{ route('purchase-orders.submit',$purchaseOrder) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Submit this purchase order for approval? It can no longer be edited unless it is returned.">Submit for approval</button></form>
        @endcan @endif

        @if($status===\App\Enums\PurchaseOrderStatus::Submitted)
            @if($isMaker)
                <span class="status-badge pending">Awaiting approval by another authorized user</span>
            @else
                @if($canApprove)
                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#approveModal">Approve</button>
                @else
                    <span class="status-badge pending">{{ $approvalTier==='director' ? 'Awaiting Director approval' : 'Awaiting authorized approver' }}</span>
                @endif
                @can('purchase_orders.reject')<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal">Return for correction</button>@endcan
            @endif
        @endif

        @if($status===\App\Enums\PurchaseOrderStatus::Approved) @can('purchase_orders.issue')
            <form method="POST" action="{{ route('purchase-orders.issue',$purchaseOrder) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Mark this purchase order as issued to the vendor?">Issue to vendor</button></form>
        @endcan @endif

        @if($status->acceptsBills()) @can('purchase_orders.close')<button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#closeModal">Close order</button>@endcan @endif
        @if(!in_array($status,[\App\Enums\PurchaseOrderStatus::Closed,\App\Enums\PurchaseOrderStatus::Cancelled],true)) @can('purchase_orders.cancel')<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancelModal">Cancel order</button>@endcan @endif
        @if(in_array($status,[\App\Enums\PurchaseOrderStatus::Closed,\App\Enums\PurchaseOrderStatus::Cancelled],true))<span class="status-badge neutral">No further actions — this order is {{ strtolower($status->label()) }}</span>@endif
    </div>
</section>

@can('purchase_orders.view_history')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Append-only record</span><h2>Approval history</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>When</th><th>Action</th><th>By</th><th>Status change</th><th>Level</th><th class="text-end">Amount</th><th>Comments</th></tr></thead><tbody>
    @foreach($purchaseOrder->approvals as $entry)
        <tr><td>{{ $entry->created_at?->format('d M Y H:i') }}</td><td><strong>{{ ucfirst($entry->action) }}</strong></td><td>{{ $entry->actor?->name ?? '—' }}</td><td>{{ $entry->from_status ? ucfirst($entry->from_status).' → ' : '' }}{{ ucfirst($entry->to_status) }}</td><td>{{ $entry->approval_tier ? ucfirst($entry->approval_tier) : '—' }}</td><td class="text-end font-monospace">{{ number_format((float)$entry->amount,2) }}</td><td>{{ $entry->comments ?: '—' }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endcan

<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('purchase-orders.approve',$purchaseOrder) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="approveModalTitle">Approve {{ $purchaseOrder->po_number }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Approve {{ $currency }} {{ number_format((float)$purchaseOrder->total_amount,2) }} for {{ $purchaseOrder->vendor->name }}? Approval commits the order; it does not post to the General Ledger or receive goods.</p><label class="form-label" for="approveComments">Comments (optional)</label><textarea class="form-control" id="approveComments" name="comments" rows="2" maxlength="2000"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Approve purchase order</button></div>
</form></div></div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('purchase-orders.reject',$purchaseOrder) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="rejectModalTitle">Return {{ $purchaseOrder->po_number }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label" for="rejectReason">Reason</label><textarea class="form-control" id="rejectReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Return for correction</button></div>
</form></div></div>

<div class="modal fade" id="closeModal" tabindex="-1" aria-labelledby="closeModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('purchase-orders.close',$purchaseOrder) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="closeModalTitle">Close {{ $purchaseOrder->po_number }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>A closed order accepts no further bills. Remaining unbilled value: {{ $currency }} {{ number_format((float)$purchaseOrder->remainingAmount(),2) }}.</p><label class="form-label" for="closeReason">Note (optional)</label><textarea class="form-control" id="closeReason" name="reason" rows="2" maxlength="2000"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Keep open</button><button class="btn btn-primary">Close order</button></div>
</form></div></div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('purchase-orders.cancel',$purchaseOrder) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="cancelModalTitle">Cancel {{ $purchaseOrder->po_number }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Cancellation is permanent and is not possible while purchase bills are linked.</p><label class="form-label" for="cancelReason">Reason</label><textarea class="form-control" id="cancelReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Keep order</button><button class="btn btn-outline-danger">Cancel order</button></div>
</form></div></div>
@endsection
