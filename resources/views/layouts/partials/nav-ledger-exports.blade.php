{{-- Sidebar links for the ledger-exports module. --}}
@if(auth()->user()->canAny(['audit_logs.view']))
    <span class="sidebar-label">Audit</span>
    @can('audit_logs.view')<a href="{{ route('audit-logs.index') }}" class="sidebar-link {{ request()->routeIs('audit-logs.*') ? 'active' : '' }}"><span class="sidebar-icon">AL</span><span class="sidebar-text">Audit Log</span></a>@endcan
@endif
