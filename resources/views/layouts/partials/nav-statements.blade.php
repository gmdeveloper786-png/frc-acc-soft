{{-- Sidebar links for the statements module. --}}
@if(auth()->user()->canAny(['financial_statements.view']))
    <span class="sidebar-label">Financial Statements</span>
    @can('financial_statements.view')<a href="{{ route('statements.income-expenditure') }}" class="sidebar-link {{ request()->routeIs('statements.income-expenditure') ? 'active' : '' }}"><span class="sidebar-icon">IE</span><span class="sidebar-text">Income & Expenditure</span></a>@endcan
    @can('financial_statements.view')<a href="{{ route('statements.balance-sheet') }}" class="sidebar-link {{ request()->routeIs('statements.balance-sheet') ? 'active' : '' }}"><span class="sidebar-icon">BS</span><span class="sidebar-text">Balance Sheet</span></a>@endcan
    @can('financial_statements.view')<a href="{{ route('statements.branch-income-expenditure') }}" class="sidebar-link {{ request()->routeIs('statements.branch-income-expenditure') ? 'active' : '' }}"><span class="sidebar-icon">BI</span><span class="sidebar-text">Branch-wise I&E</span></a>@endcan
    @can('financial_statements.view')<a href="{{ route('statements.service-analysis') }}" class="sidebar-link {{ request()->routeIs('statements.service-analysis') ? 'active' : '' }}"><span class="sidebar-icon">SA</span><span class="sidebar-text">Service Analysis</span></a>@endcan
@endif
