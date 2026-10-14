@extends('layouts.app') @section('title','Low Stock Alerts') @section('eyebrow','Inventory') @section('page-title','Low Stock Alerts')
@section('page-description','Active items whose total quantity in a branch is at or below the minimum stock level. Items with a minimum of zero, or never stocked in a branch, are not listed.')
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Item code or name">
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="category_id" aria-label="Category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.low-stock.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Item</th><th>Category</th><th>Branch</th><th class="text-end">On hand in branch</th><th class="text-end">Minimum level</th><th>Actions</th></tr></thead><tbody>
    @forelse($alerts as $alert)
        @php
            $item = $items[$alert->item_id];
        @endphp
        <tr>
            <td><code>{{ $item->item_code }}</code><span class="table-subtext">{{ $item->name }}</span></td>
            <td>{{ $item->category->name }}</td>
            <td>{{ $branchNames[$alert->branch_id] }}</td>
            <td class="text-end font-monospace"><span class="status-badge danger">{{ number_format((float) $alert->total_quantity, 4) }} {{ $item->unit_of_measure }}</span></td>
            <td class="text-end font-monospace">{{ $item->minimum_stock_level }}</td>
            <td>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.balances.index',['search'=>$item->item_code,'branch_id'=>$alert->branch_id]) }}">Balances</a>
                @can('inventory.receive')<a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.receive.create',['item_id'=>$item->id]) }}">Receive</a>@endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="6"><x-empty-state title="No low-stock alerts" description="No item in your branches is at or below its minimum stock level." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $alerts->links() }}
</section>
@endsection
