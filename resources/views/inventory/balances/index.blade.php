@extends('layouts.app') @section('title','Stock Balances') @section('eyebrow','Inventory') @section('page-title','Stock Balances')
@section('page-description','Quantity on hand per item, branch and location. A combination appears once it has had a stock movement. No values are shown because inventory is not valued.')
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Item code or name">
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="location_id" aria-label="Location"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id')==$location->id)>{{ $location->branch->name }} — {{ $location->name }}</option>@endforeach</select>
        <select class="form-select" name="category_id" aria-label="Category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select>
        <select class="form-select" name="low_stock" aria-label="Stock level"><option value="">All stock levels</option><option value="1" @selected(request()->boolean('low_stock'))>Low stock only</option></select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.balances.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Branch</th><th>Location</th><th>Item</th><th>Category</th><th class="text-end">On hand</th><th class="text-end">Minimum (branch)</th><th>Level</th><th>Actions</th></tr></thead><tbody>
    @forelse($balances as $balance)
        @php
            $isLow = $lowKeys->contains($balance->item_id.':'.$balance->branch_id);
        @endphp
        <tr>
            <td>{{ $balance->branch->name }}</td>
            <td>{{ $balance->location->name }}@if(!$balance->location->is_active)<span class="table-subtext">Inactive location</span>@endif</td>
            <td><code>{{ $balance->item->item_code }}</code><span class="table-subtext">{{ $balance->item->name }}</span></td>
            <td>{{ $balance->item->category->name }}</td>
            <td class="text-end font-monospace">{{ $balance->quantity }} {{ $balance->item->unit_of_measure }}</td>
            <td class="text-end font-monospace">{{ $balance->item->minimum_stock_level }}</td>
            <td><span class="status-badge {{ $isLow ? 'danger' : 'neutral' }}">{{ $isLow ? 'Low in branch' : 'OK' }}</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.movements.index',['item_id'=>$balance->item_id,'branch_id'=>$balance->branch_id]) }}">History</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No stock balances found" description="Balances appear after the first receipt for an item at a location, or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $balances->links() }}
</section>
@endsection
