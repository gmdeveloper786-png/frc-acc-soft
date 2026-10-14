{{-- Sidebar links for the operational-reports module. --}}
@if(auth()->user()->canAny(['fixed_assets.view', 'depreciation.view', 'inventory.view']))
    <span class="sidebar-label">Operational Reports</span>
    @canany(['fixed_assets.view', 'depreciation.view'])<a href="{{ route('reports.assets.index') }}" class="sidebar-link {{ request()->routeIs('reports.assets.*') ? 'active' : '' }}"><span class="sidebar-icon">FR</span><span class="sidebar-text">Fixed Asset Reports</span></a>@endcanany
    @can('inventory.view')<a href="{{ route('reports.inventory.index') }}" class="sidebar-link {{ request()->routeIs('reports.inventory.*') ? 'active' : '' }}"><span class="sidebar-icon">IR</span><span class="sidebar-text">Inventory Reports</span></a>@endcan
@endif
