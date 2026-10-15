{{-- Sidebar links for petty cash imprest. --}}
@can('imprest.view')
    <span class="sidebar-label">Petty Cash</span>
    <a href="{{ route('imprest.index') }}" class="sidebar-link {{ request()->routeIs('imprest.*') ? 'active' : '' }}"><span class="sidebar-icon">PI</span><span class="sidebar-text">Petty Cash Imprest</span></a>
@endcan
