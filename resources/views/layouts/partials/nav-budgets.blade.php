{{-- Sidebar links for the budgets module. --}}
@if(auth()->user()->canAny(['budgets.view', 'budget_reports.view']))
    <span class="sidebar-label">Budgets</span>
    @can('budgets.view')<a href="{{ route('budgets.index') }}" class="sidebar-link {{ request()->routeIs('budgets.*') ? 'active' : '' }}"><span class="sidebar-icon">BG</span><span class="sidebar-text">Budgets</span></a>@endcan
    @can('budget_reports.view')<a href="{{ route('budget-reports.versus-actual') }}" class="sidebar-link {{ request()->routeIs('budget-reports.versus-actual') ? 'active' : '' }}"><span class="sidebar-icon">BA</span><span class="sidebar-text">Budget vs Actual</span></a>@endcan
    @can('budget_reports.view')<a href="{{ route('budget-reports.branch-summary') }}" class="sidebar-link {{ request()->routeIs('budget-reports.branch-summary') ? 'active' : '' }}"><span class="sidebar-icon">BB</span><span class="sidebar-text">Branch Budget Summary</span></a>@endcan
    @can('budget_reports.view')<a href="{{ route('budget-reports.service-summary') }}" class="sidebar-link {{ request()->routeIs('budget-reports.service-summary') ? 'active' : '' }}"><span class="sidebar-icon">BS</span><span class="sidebar-text">Service Budget Summary</span></a>@endcan
    @can('budget_reports.view')<a href="{{ route('budget-reports.utilisation') }}" class="sidebar-link {{ request()->routeIs('budget-reports.utilisation') ? 'active' : '' }}"><span class="sidebar-icon">BU</span><span class="sidebar-text">Budget Utilisation</span></a>@endcan
@endif
