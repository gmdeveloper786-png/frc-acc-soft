@extends('layouts.app') @section('title','Inventory Items') @section('eyebrow','Inventory') @section('page-title','Inventory Items')
@section('page-description','Items are defined once for the organisation. Stock is held per branch and location, in quantities only.')
@section('page-actions')@can('inventory_items.manage')<a class="btn btn-primary" href="{{ route('inventory.items.create') }}">+ New item</a>@endcan @endsection
@section('content')
@include('inventory._tabs')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Item code or name">
        <select class="form-select" name="category_id" aria-label="Category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select>
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.items.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Item code</th><th>Name</th><th>Category</th><th>Unit</th><th class="text-end">Minimum level</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($items as $item)
        <tr>
            <td><code>{{ $item->item_code }}</code></td>
            <td>{{ $item->name }}@if($item->description)<span class="table-subtext">{{ \Illuminate\Support\Str::limit($item->description, 80) }}</span>@endif</td>
            <td>{{ $item->category->name }}</td>
            <td>{{ $item->unit_of_measure }}</td>
            <td class="text-end font-monospace">{{ $item->minimum_stock_level }}</td>
            <td><span class="status-badge {{ $item->is_active ? 'success' : 'neutral' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.movements.index',['item_id'=>$item->id]) }}">History</a>
                @can('inventory_items.manage')<a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.items.edit',$item) }}">Edit</a>@endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No inventory items found" description="Create an item or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $items->links() }}
</section>
@endsection
