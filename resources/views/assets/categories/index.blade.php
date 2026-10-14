@extends('layouts.app') @section('title','Asset Categories') @section('eyebrow','Fixed Assets') @section('page-title','Asset Categories')
@section('page-description','Each category carries the asset, accumulated depreciation and depreciation expense ledgers that its assets post to.')
@section('page-actions')@can('asset_categories.manage')<a class="btn btn-primary" href="{{ route('asset-categories.create') }}">+ New category</a>@endcan @endsection
@section('content')
<section class="surface-card">
    <form class="filter-bar" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Code or name">
        <select class="form-select" name="status"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('asset-categories.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Code</th><th>Name</th><th class="text-end">Default life</th><th>Asset ledger</th><th>Accumulated depreciation</th><th>Depreciation expense</th><th class="text-end">Assets</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($categories as $category)
        <tr>
            <td><code>{{ $category->code }}</code></td>
            <td>{{ $category->name }}@if($category->description)<span class="table-subtext">{{ $category->description }}</span>@endif</td>
            <td class="text-end">{{ $category->default_useful_life_months }} months</td>
            <td>{{ $category->assetAccount?->code }}<span class="table-subtext">{{ $category->assetAccount?->name }}</span></td>
            <td>{{ $category->accumulatedDepreciationAccount?->code }}<span class="table-subtext">{{ $category->accumulatedDepreciationAccount?->name }}</span></td>
            <td>{{ $category->depreciationExpenseAccount?->code }}<span class="table-subtext">{{ $category->depreciationExpenseAccount?->name }}</span></td>
            <td class="text-end">{{ $category->assets_count }}</td>
            <td><span class="status-badge {{ $category->is_active ? 'success' : 'neutral' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td>@can('asset_categories.manage')<a class="btn btn-sm btn-outline-primary" href="{{ route('asset-categories.edit',$category) }}">Edit</a>@endcan</td>
        </tr>
    @empty
        <tr><td colspan="9"><x-empty-state title="No asset categories found" description="Create a category before registering fixed assets, or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $categories->links() }}
</section>
@endsection
