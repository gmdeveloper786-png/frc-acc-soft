{{-- Sidebar links for the cashflow module. --}}
@if(auth()->user()->canAny(['financial_statements.view', 'cash_flow.manage_classification']))
    <span class="sidebar-label">Cash Statements</span>
    @can('financial_statements.view')<a href="{{ route('cash-flow.statement') }}" class="sidebar-link {{ request()->routeIs('cash-flow.statement') ? 'active' : '' }}"><span class="sidebar-icon">CF</span><span class="sidebar-text">Cash Flow Statement</span></a>@endcan
    @can('financial_statements.view')<a href="{{ route('cash-flow.receipts-payments') }}" class="sidebar-link {{ request()->routeIs('cash-flow.receipts-payments') ? 'active' : '' }}"><span class="sidebar-icon">RP</span><span class="sidebar-text">Receipts &amp; Payments</span></a>@endcan
    @canany(['financial_statements.view', 'cash_flow.manage_classification'])<a href="{{ route('cash-flow.classification.index') }}" class="sidebar-link {{ request()->routeIs('cash-flow.classification.*') ? 'active' : '' }}"><span class="sidebar-icon">CC</span><span class="sidebar-text">Cash Flow Classification</span></a>@endcanany
@endif
