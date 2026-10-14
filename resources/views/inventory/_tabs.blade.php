{{-- Links between the three master-data screens of the inventory module. --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm {{ request()->routeIs('inventory.items.*') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('inventory.items.index') }}">Items</a>
    <a class="btn btn-sm {{ request()->routeIs('inventory.categories.*') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('inventory.categories.index') }}">Categories</a>
    <a class="btn btn-sm {{ request()->routeIs('inventory.locations.*') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('inventory.locations.index') }}">Locations</a>
</div>
