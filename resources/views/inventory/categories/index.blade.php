@extends('layouts.app') @section('title','Inventory Categories') @section('eyebrow','Inventory') @section('page-title','Inventory Categories')
@section('page-description','Categories group inventory items. They are maintained here, not fixed in the software.')
@section('content')
@include('inventory._tabs')
@include('inventory._errors')
@can('inventory_items.manage')
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">New</span><h2>Add category</h2></div></div>
    <form class="row g-3 align-items-end" method="POST" action="{{ route('inventory.categories.store') }}" data-submit-once>@csrf
        <div class="col-md-6"><label class="form-label" for="new_category_name">Name</label><input class="form-control" id="new_category_name" name="name" maxlength="100" required></div>
        <div class="col-md-3"><button class="btn btn-primary">Add category</button></div>
    </form>
</section>
@endcan
<section class="surface-card">
    <form class="filter-bar" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Category name">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.categories.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Category</th><th class="text-end">Items</th><th>Status</th>@can('inventory_items.manage')<th>Edit</th>@endcan</tr></thead><tbody>
    @forelse($categories as $category)
        <tr>
            <td>{{ $category->name }}</td>
            <td class="text-end font-monospace">{{ number_format($category->items_count) }}</td>
            <td><span class="status-badge {{ $category->is_active ? 'success' : 'neutral' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
            @can('inventory_items.manage')
            <td>
                <form class="d-flex flex-wrap gap-2" method="POST" action="{{ route('inventory.categories.update',$category) }}" data-submit-once>@csrf @method('PUT')
                    <input class="form-control form-control-sm w-auto" name="name" maxlength="100" value="{{ $category->name }}" aria-label="Category name" required>
                    <select class="form-select form-select-sm w-auto" name="is_active" aria-label="Status"><option value="1" @selected($category->is_active)>Active</option><option value="0" @selected(!$category->is_active)>Inactive</option></select>
                    <button class="btn btn-sm btn-outline-primary">Save</button>
                </form>
            </td>
            @endcan
        </tr>
    @empty
        <tr><td colspan="4"><x-empty-state title="No categories found" description="Add a category before creating inventory items." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $categories->links() }}
</section>
@endsection
