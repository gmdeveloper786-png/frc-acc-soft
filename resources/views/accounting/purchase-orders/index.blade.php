@extends('layouts.app') @section('title','Purchase Orders') @section('eyebrow','Procurement') @section('page-title','Purchase Orders')
@section('page-description','Procurement commitments. A purchase order never posts to the General Ledger; payables are recognised by purchase bills.')
@section('page-actions')@can('purchase_orders.create')<a class="btn btn-primary" href="{{ route('purchase-orders.create') }}">+ New purchase order</a>@endcan @endsection
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="PO number, reference or description">
        <select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Enums\PurchaseOrderStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <select class="form-select" name="vendor_id"><option value="">All vendors</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(request('vendor_id')==$vendor->id)>{{ $vendor->name }}</option>@endforeach</select>
        <select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <input class="form-control" type="date" name="from" value="{{ request('from') }}" aria-label="PO date from">
        <input class="form-control" type="date" name="to" value="{{ request('to') }}" aria-label="PO date to">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('purchase-orders.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>PO number</th><th>PO date</th><th>Vendor</th><th>Branch</th><th>Status</th><th class="text-end">Total</th><th>Actions</th></tr></thead><tbody>
    @forelse($orders as $order)
        <tr>
            <td><code>{{ $order->po_number }}</code>@if($order->reference)<span class="table-subtext">Ref {{ $order->reference }}</span>@endif</td>
            <td>{{ $order->po_date->format('d M Y') }}</td>
            <td>{{ $order->vendor->name }}</td>
            <td>{{ $order->branch->name }}</td>
            <td><span class="status-badge {{ $order->status->badge() }}">{{ $order->status->label() }}</span>@if($order->approval_tier==='director')<span class="table-subtext">Director approval</span>@endif</td>
            <td class="text-end font-monospace">{{ $order->currency }} {{ number_format((float)$order->total_amount,2) }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-orders.show',$order) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No purchase orders found" description="Create a purchase order or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $orders->links() }}
</section>
@endsection
