{{-- Sidebar links for payroll reports. --}}
@if(auth()->user()->canAny(['payroll.view']))
    <span class="sidebar-label">Payroll Reports</span>
    @can('payroll.view')<a href="{{ route('payroll-reports.index') }}" class="sidebar-link {{ request()->routeIs('payroll-reports.*') ? 'active' : '' }}"><span class="sidebar-icon">PY</span><span class="sidebar-text">Payroll Reports</span></a>@endcan
@endif
