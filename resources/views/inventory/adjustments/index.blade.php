@extends('layouts.app') @section('title','Pending Stock Adjustments') @section('eyebrow','Inventory') @section('page-title','Pending Stock Adjustments')
@section('page-description','An adjustment changes stock only when a user other than the requester approves it. Decided adjustments remain in the movement history.')
@section('page-actions')@can('inventory.adjust')<a class="btn btn-primary" href="{{ route('inventory.adjust.create') }}">+ Request adjustment</a>@endcan @endsection
@section('content')
@include('inventory._errors')
<section class="surface-card">
    <form class="filter-bar" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Item code or name">
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.adjustments.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Date</th><th>Item</th><th>Branch / location</th><th class="text-end">Change</th><th class="text-end">On hand now</th><th>Reason</th><th>Requested by</th>@can('inventory.approve_adjustment')<th>Decision</th>@endcan</tr></thead><tbody>
    @forelse($adjustments as $adjustment)
        <tr>
            <td>{{ $adjustment->transaction_date->format('d M Y') }}<span class="table-subtext">#{{ $adjustment->id }}</span></td>
            <td><code>{{ $adjustment->item->item_code }}</code><span class="table-subtext">{{ $adjustment->item->name }}</span></td>
            <td>{{ $adjustment->branch->name }}<span class="table-subtext">{{ $adjustment->location->name }}</span></td>
            <td class="text-end font-monospace">{{ $adjustment->quantity }} {{ $adjustment->item->unit_of_measure }}</td>
            <td class="text-end font-monospace">{{ $onHand[$adjustment->item_id.':'.$adjustment->location_id] ?? '0.0000' }}</td>
            <td>{{ $adjustment->notes }}@if($adjustment->reference)<span class="table-subtext">Ref {{ $adjustment->reference }}</span>@endif</td>
            <td>{{ $adjustment->creator->name }}</td>
            @can('inventory.approve_adjustment')
            <td>
                @if($adjustment->created_by === auth()->id())
                    <span class="table-subtext">You requested this adjustment; another user must decide it.</span>
                @else
                    <form class="mb-2" method="POST" action="{{ route('inventory.adjustments.approve',$adjustment) }}" data-submit-once>@csrf<button class="btn btn-sm btn-primary" data-confirm="Approve this adjustment and change the stock balance?">Approve</button></form>
                    <form class="d-flex flex-wrap gap-2" method="POST" action="{{ route('inventory.adjustments.reject',$adjustment) }}" data-submit-once>@csrf
                        <input class="form-control form-control-sm w-auto" name="reason" minlength="5" maxlength="2000" placeholder="Reason for rejection" aria-label="Reason for rejection" required>
                        <button class="btn btn-sm btn-outline-danger" data-confirm="Reject this adjustment? Stock will not change.">Reject</button>
                    </form>
                @endif
            </td>
            @endcan
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No pending adjustments" description="Adjustments waiting for approval in your branches will appear here." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $adjustments->links() }}
</section>
@endsection
