{{-- Sidebar links for bank reconciliation. --}}
@can('bank_reconciliation.view')
    <span class="sidebar-label">Bank Reconciliation</span>
    <a href="{{ route('bank-reconciliations.index') }}" class="sidebar-link {{ request()->routeIs('bank-reconciliations.*') ? 'active' : '' }}"><span class="sidebar-icon">BR</span><span class="sidebar-text">Bank Reconciliation</span></a>
    <a href="{{ route('bank-statements.index') }}" class="sidebar-link {{ request()->routeIs('bank-statements.*') ? 'active' : '' }}"><span class="sidebar-icon">BS</span><span class="sidebar-text">Bank Statements</span></a>
@endcan
