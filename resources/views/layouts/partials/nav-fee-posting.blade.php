{{-- Sidebar links for FRC fee posting. --}}
@if(auth()->user()->canAny(['frc_integration.view', 'fee_settlement.manage']))
    <span class="sidebar-label">Fee Posting</span>
    <a href="{{ route('fee-settlement-mappings.index') }}" class="sidebar-link {{ request()->routeIs('fee-settlement-mappings.*') ? 'active' : '' }}"><span class="sidebar-icon">SM</span><span class="sidebar-text">Fee Settlement Mapping</span></a>
    @can('frc_integration.view')
        <a href="{{ route('fee-posting.payments.index') }}" class="sidebar-link {{ request()->routeIs('fee-posting.payments.*') ? 'active' : '' }}"><span class="sidebar-icon">FP</span><span class="sidebar-text">Fee Posting Preview</span></a>
        @if(app(\App\Services\FeePosting\FeePostingSettings::class)->isAccrual())
            <a href="{{ route('fee-posting.enrollments.index') }}" class="sidebar-link {{ request()->routeIs('fee-posting.enrollments.*') ? 'active' : '' }}"><span class="sidebar-icon">ER</span><span class="sidebar-text">Enrollment Recognition</span></a>
        @endif
        <a href="{{ route('fee-posting.postings.index') }}" class="sidebar-link {{ request()->routeIs('fee-posting.postings.*') ? 'active' : '' }}"><span class="sidebar-icon">PR</span><span class="sidebar-text">Posted Fee Receipts</span></a>
    @endcan
@endif
