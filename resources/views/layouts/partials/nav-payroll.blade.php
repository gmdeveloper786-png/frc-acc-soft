{{-- Sidebar links for the payroll module. --}}
@if(auth()->user()->canAny(['employees.view', 'payroll.view', 'payroll.import', 'payroll_components.manage']))
    <span class="sidebar-label">Payroll</span>
    @can('employees.view')<a href="{{ route('employees.index') }}" class="sidebar-link {{ request()->routeIs('employees.*') ? 'active' : '' }}"><span class="sidebar-icon">EM</span><span class="sidebar-text">Employees</span></a>@endcan
    @can('payroll.view')<a href="{{ route('payroll-runs.index') }}" class="sidebar-link {{ request()->routeIs('payroll-runs.*') ? 'active' : '' }}"><span class="sidebar-icon">PR</span><span class="sidebar-text">Payroll Runs</span></a>@endcan
    @can('payroll.import')<a href="{{ route('payroll-imports.index') }}" class="sidebar-link {{ request()->routeIs('payroll-imports.*') ? 'active' : '' }}"><span class="sidebar-icon">PI</span><span class="sidebar-text">Payroll Import</span></a>@endcan
    @can('payroll_components.manage')<a href="{{ route('payroll-components.index') }}" class="sidebar-link {{ request()->routeIs('payroll-components.*') ? 'active' : '' }}"><span class="sidebar-icon">PC</span><span class="sidebar-text">Payroll Components</span></a>@endcan
@endif
