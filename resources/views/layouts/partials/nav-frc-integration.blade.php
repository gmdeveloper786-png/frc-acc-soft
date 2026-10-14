{{-- Sidebar links for the FRC financial integration. --}}
@can('frc_integration.view')
    <span class="sidebar-label">FRC Fee Integration</span>
    <a href="{{ route('frc-integration.index') }}" class="sidebar-link {{ request()->routeIs('frc-integration.index') ? 'active' : '' }}"><span class="sidebar-icon">FI</span><span class="sidebar-text">Financial Integration</span></a>
    <a href="{{ route('frc-integration.payments.index') }}" class="sidebar-link {{ request()->routeIs('frc-integration.payments.index', 'frc-integration.payments.show') ? 'active' : '' }}"><span class="sidebar-icon">SP</span><span class="sidebar-text">Source Payments</span></a>
    <a href="{{ route('frc-integration.payments.unmapped') }}" class="sidebar-link {{ request()->routeIs('frc-integration.payments.unmapped') ? 'active' : '' }}"><span class="sidebar-icon">UM</span><span class="sidebar-text">Unmapped Payments</span></a>
    <a href="{{ route('frc-integration.runs.index') }}" class="sidebar-link {{ request()->routeIs('frc-integration.runs.*') ? 'active' : '' }}"><span class="sidebar-icon">SH</span><span class="sidebar-text">Sync History</span></a>
    <a href="{{ route('frc-integration.exceptions.index') }}" class="sidebar-link {{ request()->routeIs('frc-integration.exceptions.*') ? 'active' : '' }}"><span class="sidebar-icon">SE</span><span class="sidebar-text">Sync Exceptions</span></a>
    <a href="{{ route('frc-integration.reconciliation') }}" class="sidebar-link {{ request()->routeIs('frc-integration.reconciliation*') ? 'active' : '' }}"><span class="sidebar-icon">RC</span><span class="sidebar-text">Fee Reconciliation</span></a>
@endcan
