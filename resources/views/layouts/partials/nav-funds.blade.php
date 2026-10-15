{{-- Sidebar links for funds and grants. --}}
@can('funds.view')
    <span class="sidebar-label">Funds & Grants</span>
    <a href="{{ route('funds.index') }}" class="sidebar-link {{ request()->routeIs('funds.*') ? 'active' : '' }}"><span class="sidebar-icon">FD</span><span class="sidebar-text">Funds</span></a>
    @can('fund_reports.view')<a href="{{ route('fund-reports.income-expenditure') }}" class="sidebar-link {{ request()->routeIs('fund-reports.income-expenditure') ? 'active' : '' }}"><span class="sidebar-icon">FI</span><span class="sidebar-text">Fund-wise I&E</span></a>@endcan
    @can('fund_reports.view')<a href="{{ route('fund-reports.balance-summary') }}" class="sidebar-link {{ request()->routeIs('fund-reports.balance-summary') ? 'active' : '' }}"><span class="sidebar-icon">FB</span><span class="sidebar-text">Fund Balances</span></a>@endcan
    @can('fund_reports.view')<a href="{{ route('fund-reports.grant-utilisation') }}" class="sidebar-link {{ request()->routeIs('fund-reports.grant-utilisation', 'fund-reports.donor-report') ? 'active' : '' }}"><span class="sidebar-icon">GU</span><span class="sidebar-text">Grant Utilisation</span></a>@endcan
@endcan
