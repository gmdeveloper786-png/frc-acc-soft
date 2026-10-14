@extends('layouts.app') @section('title','Inventory Locations') @section('eyebrow','Inventory') @section('page-title','Inventory Locations')
@section('page-description','Stores and rooms where stock is kept. Each location belongs to one branch and stays in it.')
@section('content')
@include('inventory._tabs')
@include('inventory._errors')
@can('inventory_items.manage')
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">New</span><h2>Add location</h2></div></div>
    <form class="row g-3 align-items-end" method="POST" action="{{ route('inventory.locations.store') }}" data-submit-once>@csrf
        <div class="col-md-4"><label class="form-label" for="new_location_branch">Branch</label><select class="form-select" id="new_location_branch" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="col-md-5"><label class="form-label" for="new_location_name">Location name</label><input class="form-control" id="new_location_name" name="name" maxlength="100" placeholder="Main store" required></div>
        <div class="col-md-3"><button class="btn btn-primary">Add location</button></div>
    </form>
</section>
@endcan
<section class="surface-card">
    <form class="filter-bar" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Location name">
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('inventory.locations.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Branch</th><th>Location</th><th>Status</th>@can('inventory_items.manage')<th>Edit</th>@endcan</tr></thead><tbody>
    @forelse($locations as $location)
        <tr>
            <td>{{ $location->branch->name }}</td>
            <td>{{ $location->name }}</td>
            <td><span class="status-badge {{ $location->is_active ? 'success' : 'neutral' }}">{{ $location->is_active ? 'Active' : 'Inactive' }}</span></td>
            @can('inventory_items.manage')
            <td>
                <form class="d-flex flex-wrap gap-2" method="POST" action="{{ route('inventory.locations.update',$location) }}" data-submit-once>@csrf @method('PUT')
                    <input class="form-control form-control-sm w-auto" name="name" maxlength="100" value="{{ $location->name }}" aria-label="Location name" required>
                    <select class="form-select form-select-sm w-auto" name="is_active" aria-label="Status"><option value="1" @selected($location->is_active)>Active</option><option value="0" @selected(!$location->is_active)>Inactive</option></select>
                    <button class="btn btn-sm btn-outline-primary">Save</button>
                </form>
            </td>
            @endcan
        </tr>
    @empty
        <tr><td colspan="4"><x-empty-state title="No locations found" description="Add a location to a branch before receiving stock." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $locations->links() }}
</section>
@endsection
