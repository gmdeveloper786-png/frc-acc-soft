@extends('layouts.app') @section('title','Stock Movement History') @section('eyebrow','Inventory') @section('page-title','Stock Movement History')
@section('page-description','The append-only stock ledger. Movements are never edited or deleted; only posted movements count towards a balance.')
@section('page-actions')
    @can('inventory.receive')<a class="btn btn-primary" href="{{ route('inventory.receive.create') }}">Receive</a>@endcan
    @can('inventory.issue')<a class="btn btn-outline-primary" href="{{ route('inventory.issue.create') }}">Issue</a>@endcan
    @can('inventory.transfer')<a class="btn btn-outline-primary" href="{{ route('inventory.transfer.create') }}">Transfer</a>@endcan
@endsection
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Reference or recipient">
        <select class="form-select" name="item_id" aria-label="Item"><option value="">All items</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(request('item_id')==$item->id)>{{ $item->item_code }} — {{ $item->name }}</option>@endforeach</select>
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="type" aria-label="Movement type"><option value="">All types</option>@foreach(\App\Enums\InventoryMovementType::cases() as $type)<option value="{{ $type->value }}" @selected(request('type')===$type->value)>{{ $type->label() }}</option>@endforeach</select>
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach(\App\Enums\InventoryMovementStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <input class="form-control" type="date" name="from" value="{{ request('from') }}" aria-label="Date from">
        <input class="form-control" type="date" name="to" value="{{ request('to') }}" aria-label="Date to">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.movements.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Item</th><th>Branch / location</th><th>Type</th><th class="text-end">Quantity</th><th>Recipient / reference</th><th>Status</th><th>Recorded by</th></tr></thead><tbody>
    @forelse($movements as $movement)
        <tr>
            <td>{{ $movement->transaction_date->format('d M Y') }}<span class="table-subtext">#{{ $movement->id }}</span></td>
            <td><code>{{ $movement->item->item_code }}</code><span class="table-subtext">{{ $movement->item->name }}</span></td>
            <td>{{ $movement->branch->name }}<span class="table-subtext">{{ $movement->location->name }}</span></td>
            <td>{{ $movement->type->label() }}@if($movement->transfer_group)<span class="table-subtext">Transfer {{ \Illuminate\Support\Str::limit($movement->transfer_group, 8, '') }}</span>@endif</td>
            <td class="text-end font-monospace">{{ $movement->quantity }} {{ $movement->item->unit_of_measure }}</td>
            <td>
                {{ $movement->issued_to ?: '—' }}
                @if($movement->reference)<span class="table-subtext">Ref {{ $movement->reference }}</span>@endif
                @if($movement->purchaseOrder)<span class="table-subtext">PO {{ $movement->purchaseOrder->po_number }}</span>@endif
                @if($movement->notes)<span class="table-subtext">{{ \Illuminate\Support\Str::limit($movement->notes, 80) }}</span>@endif
            </td>
            <td>
                <span class="status-badge {{ $movement->status->badge() }}">{{ $movement->status->label() }}</span>
                @if($movement->reviewer)<span class="table-subtext">Reviewed by {{ $movement->reviewer->name }}</span>@endif
                @if($movement->rejection_reason)<span class="table-subtext">{{ \Illuminate\Support\Str::limit($movement->rejection_reason, 80) }}</span>@endif
            </td>
            <td>{{ $movement->creator->name }}<span class="table-subtext">{{ $movement->created_at->format('d M Y H:i') }}</span></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No stock movements found" description="Record a movement or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $movements->links() }}
</section>
@endsection
