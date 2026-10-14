@extends('layouts.app') @section('title','Inventory') @section('eyebrow','Inventory') @section('page-title','Inventory Overview')
@section('page-description','Quantity-only stock keeping per branch and location. Inventory is not valued here and no movement posts to the General Ledger.')
@section('page-actions')
    @can('inventory.receive')<a class="btn btn-primary" href="{{ route('inventory.receive.create') }}">Receive stock</a>@endcan
    @can('inventory.issue')<a class="btn btn-outline-primary" href="{{ route('inventory.issue.create') }}">Issue stock</a>@endcan
@endsection
@section('content')
<div class="kpi-grid">
    <x-kpi-card label="Active items" value="{{ number_format($activeItems) }}" icon="IT" tone="navy" status="Item master" description="{{ number_format($inactiveItems) }} inactive item(s) are not counted"/>
    <x-kpi-card label="Low-stock alerts" value="{{ number_format($lowStockCount) }}" icon="LS" tone="amber" status="Your branches" description="Item and branch pairs at or below the minimum stock level"/>
    <x-kpi-card label="Pending adjustments" value="{{ number_format($pendingAdjustments) }}" icon="AJ" tone="teal" status="Awaiting approval" description="Adjustments change stock only after a second user approves them"/>
</div>

<section class="surface-card mt-4">
    <div class="card-heading"><div><span class="section-kicker">Negative stock {{ $allowsNegativeStock ? 'is allowed by settings' : 'is not allowed' }}</span><h2>Quick links</h2></div></div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="{{ route('inventory.balances.index') }}">Stock balances</a>
        <a class="btn btn-outline-primary" href="{{ route('inventory.low-stock.index') }}">Low-stock alerts</a>
        <a class="btn btn-outline-primary" href="{{ route('inventory.adjustments.index') }}">Pending adjustments</a>
        <a class="btn btn-outline-primary" href="{{ route('inventory.movements.index') }}">Movement history</a>
        <a class="btn btn-outline-primary" href="{{ route('inventory.items.index') }}">Items</a>
        @can('inventory.return')<a class="btn btn-outline-primary" href="{{ route('inventory.return.create') }}">Record a return</a>@endcan
        @can('inventory.adjust')<a class="btn btn-outline-primary" href="{{ route('inventory.adjust.create') }}">Request adjustment</a>@endcan
        @can('inventory.transfer')<a class="btn btn-outline-primary" href="{{ route('inventory.transfer.create') }}">Branch transfer</a>@endcan
    </div>
</section>

<section class="surface-card mt-4">
    <div class="card-heading"><div><span class="section-kicker">Latest 10 in your branches</span><h2>Recent movements</h2></div><a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.movements.index') }}">View all</a></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Item</th><th>Branch / location</th><th>Type</th><th class="text-end">Quantity</th><th>Status</th><th>Recorded by</th></tr></thead><tbody>
    @forelse($recentMovements as $movement)
        <tr>
            <td>{{ $movement->transaction_date->format('d M Y') }}</td>
            <td><code>{{ $movement->item->item_code }}</code><span class="table-subtext">{{ $movement->item->name }}</span></td>
            <td>{{ $movement->branch->name }}<span class="table-subtext">{{ $movement->location->name }}</span></td>
            <td>{{ $movement->type->label() }}</td>
            <td class="text-end font-monospace">{{ $movement->quantity }} {{ $movement->item->unit_of_measure }}</td>
            <td><span class="status-badge {{ $movement->status->badge() }}">{{ $movement->status->label() }}</span></td>
            <td>{{ $movement->creator->name }}</td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No stock movements yet" description="Receipts, issues, returns, adjustments and transfers will appear here." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
