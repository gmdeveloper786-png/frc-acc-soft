{{-- Sidebar links for the assets module. --}}
@if(auth()->user()->canAny(['asset_categories.view', 'fixed_assets.view', 'depreciation.view']))
    <span class="sidebar-label">Fixed Assets</span>
    @can('fixed_assets.view')<a href="{{ route('fixed-assets.index') }}" class="sidebar-link {{ request()->routeIs('fixed-assets.*') && ! request()->routeIs('fixed-assets.register') ? 'active' : '' }}"><span class="sidebar-icon">FA</span><span class="sidebar-text">Fixed Assets</span></a>@endcan
    @can('asset_categories.view')<a href="{{ route('asset-categories.index') }}" class="sidebar-link {{ request()->routeIs('asset-categories.*') ? 'active' : '' }}"><span class="sidebar-icon">AC</span><span class="sidebar-text">Asset Categories</span></a>@endcan
    @can('depreciation.view')<a href="{{ route('depreciation-runs.index') }}" class="sidebar-link {{ request()->routeIs('depreciation-runs.*') ? 'active' : '' }}"><span class="sidebar-icon">DP</span><span class="sidebar-text">Depreciation Runs</span></a>@endcan
    @can('fixed_assets.view')<a href="{{ route('fixed-assets.register') }}" class="sidebar-link {{ request()->routeIs('fixed-assets.register') ? 'active' : '' }}"><span class="sidebar-icon">AR</span><span class="sidebar-text">Asset Register</span></a>@endcan
@endif
