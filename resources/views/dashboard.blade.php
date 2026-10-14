@extends('layouts.app')

@section('title', 'Dashboard')
@section('eyebrow', 'Financial control centre')
@section('page-title', 'Accounting Dashboard')
@section('page-description', 'Figures come from posted journals and module records of your branches. Anything that cannot be calculated is shown as unavailable.')

@section('page-actions')
    <span class="context-chip"><span class="context-dot"></span>{{ $appSettings['currency_code'] }} reporting</span>
@endsection

@section('content')
    @php
        $currency = $appSettings['currency_code'];
        $branchSummary = $all_branches ? 'All branches (consolidated)' : ($branches->isEmpty() ? 'No branch assigned' : $branches->take(3)->implode(', ').($branches->count() > 3 ? ' and '.($branches->count() - 3).' more' : ''));
    @endphp
    <section class="context-strip" aria-label="Current accounting context">
        <div class="context-item">
            <span class="context-icon">B</span>
            <div><small>Branch context</small><strong>{{ $all_branches ? 'All branches' : $branches->count().' '.Str::plural('branch', $branches->count()) }}</strong><p>{{ $branchSummary }}</p></div>
        </div>
        <div class="context-divider"></div>
        <div class="context-item">
            <span class="context-icon">FY</span>
            <div><small>Fiscal period</small><strong>{{ $fiscal_year?->name ?? 'No open fiscal year' }}</strong><p>@if($fiscal_year){{ $fiscal_year->start_date->format($dateFormat) }} to {{ $fiscal_year->end_date->format($dateFormat) }}@else Income, expense and budget figures need an open fiscal year.@endif</p></div>
        </div>
        <div class="context-divider"></div>
        <div class="context-item">
            <span class="context-icon">OD</span>
            <div><small>Initial opening date</small><strong>{{ \Illuminate\Support\Carbon::parse($openingDate)->format($dateFormat) }}</strong><p>Earlier history remains in the approved archive source.</p></div>
        </div>
    </section>

    @if($chart_is_empty)
        <div class="alert alert-warning" role="alert"><strong>Accounting setup required.</strong> The Chart of Accounts is empty, so no financial figure can be calculated yet. Nothing below is an estimate.</div>
    @endif

    <div class="kpi-grid">
        @foreach ($figures as $figure)
            @php
                $display = $figure['value'] === null ? null : ($figure['percent'] ? number_format((float) $figure['value'], 2).'%' : $currency.' '.number_format((float) $figure['value'], 2));
            @endphp
            <x-kpi-card :label="$figure['label']" :value="$display" :tone="$figure['tone']" :icon="$figure['icon']" :status="$figure['status']" :description="$figure['description']" />
        @endforeach
        <x-kpi-card label="Pending Approvals" :value="$pending === null ? null : (string) $pending['total']" tone="slate" icon="A" :status="$pending === null ? 'Not available' : 'Awaiting review'" :description="$pending === null ? 'You do not have access to any approval queue.' : 'Submitted records in the modules you can view.'" />
    </div>

    <div class="dashboard-grid">
        <section class="surface-card">
            <div class="card-heading">
                <div><span class="section-kicker">General Ledger</span><h2>Recent Posted Journals</h2></div>
                @if($recent_journals !== null)<a class="btn btn-sm btn-outline-primary" href="{{ route('journals.index') }}">All journals</a>@endif
            </div>
            @if($recent_journals === null)
                <x-empty-state title="Not available" description="Journals are shown to users who may view the journal register." />
            @elseif($recent_journals->isEmpty())
                <x-empty-state title="No posted journals yet" description="Posted journals of your branches will be listed here. Drafts and journals awaiting approval are never counted." />
            @else
                <div class="table-responsive"><table class="table accounting-table">
                    <thead><tr><th>Voucher</th><th>Date</th><th>Branch</th><th>Narration</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($recent_journals as $journal)
                        <tr>
                            <td><a href="{{ route('journals.show', $journal) }}">{{ $journal->voucher_number ?? 'Unnumbered' }}</a><span class="table-subtext">{{ $journal->voucherType?->name }}</span></td>
                            <td>{{ $journal->transaction_date->format($dateFormat) }}</td>
                            <td>{{ $journal->branch?->name }}</td>
                            <td>{{ Str::limit($journal->narration, 60) }}</td>
                            <td class="text-end font-monospace">@if($journal->source_type === 'payroll_run' && ! auth()->user()->can('payroll.view_sensitive'))<span class="text-muted">Restricted</span>@else{{ number_format((float) $journal->total_debit, 2) }}@endif</td>
                            <td><span class="status-badge {{ $journal->status === \App\Enums\JournalStatus::Reversed ? 'danger' : 'success' }}">{{ ucfirst($journal->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>

        <section class="surface-card">
            <div class="card-heading">
                <div><span class="section-kicker">Workflow</span><h2>Pending Approvals</h2></div>
                @if($pending !== null)<span class="status-badge {{ $pending['total'] > 0 ? 'pending' : 'success' }}">{{ $pending['total'] }} waiting</span>@endif
            </div>
            @if($pending === null)
                <x-empty-state title="Not available" description="You do not have access to any approval queue." />
            @else
                <dl class="definition-list">
                    @foreach($pending['items'] as $item)
                        <div><dt>@if($item['route'])<a href="{{ route($item['route']) }}">{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif</dt><dd>{{ $item['count'] }}</dd></div>
                    @endforeach
                </dl>
                <p class="card-copy">Counts only. Records are limited to your branches and to modules you may view.</p>
            @endif
        </section>
    </div>

    <div class="dashboard-grid lower-grid">
        <section class="surface-card">
            <div class="card-heading">
                <div><span class="section-kicker">Attention needed</span><h2>Setup Requirements</h2></div>
                <span class="status-badge {{ $setup['ready'] ? 'success' : 'pending' }}">{{ $setup['ready'] ? 'Complete' : 'Incomplete' }}</span>
            </div>
            <div class="notice-list">
                @if($setup['ready'])
                    <div class="notice-item neutral"><span>✓</span><div><strong>Accounting setup is complete</strong><p>All posting prerequisites are in place.</p></div></div>
                @elseif($setup['detailed'])
                    @foreach($setup['checks'] as $check)
                        <div class="notice-item info"><span>!</span><div><strong>{{ $check['label'] }}</strong><p>{{ $check['issues'][0] ?? $check['summary'] }}@if(count($check['issues']) > 1) (and {{ count($check['issues']) - 1 }} more)@endif</p>@if($check['route'])<a href="{{ route($check['route']) }}">{{ $check['action'] }}</a>@endif</div></div>
                    @endforeach
                    @if(Route::has('accounting-setup.readiness'))<a class="btn btn-sm btn-outline-primary" href="{{ route('accounting-setup.readiness') }}">Open the readiness checklist</a>@endif
                @else
                    <div class="notice-item info"><span>i</span><div><strong>Accounting setup is incomplete</strong><p>Some posting prerequisites are still missing. Ask an administrator or accountant to complete the setup checklist.</p></div></div>
                @endif
            </div>
        </section>

        <section class="surface-card integration-card">
            <div class="card-heading">
                <div><span class="section-kicker">Integration</span><h2>FRC API</h2></div>
                <span class="status-badge {{ $integrationEnabled ? 'pending' : 'danger' }}">{{ $integrationEnabled ? 'Enabled' : 'Disabled' }}</span>
            </div>
            @if (session('integration_success'))
                <div class="alert alert-success">{{ session('integration_success') }}</div>
            @endif
            @if (session('integration_error'))
                <div class="alert alert-warning">{{ session('integration_error') }}</div>
            @endif
            <p class="card-copy">Read-only connectivity uses the published services endpoint. Credentials remain server-side and are never rendered here.</p>
            <dl class="definition-list">
                <div><dt>Configuration</dt><dd>{{ $integrationConfigured ? 'Token configured' : 'Token required' }}</dd></div>
                <div><dt>Data sync</dt><dd>Manual authorized sync available</dd></div>
            </dl>
            @can('services.sync')
            <form method="POST" action="{{ route('integrations.frc.check') }}">
                @csrf
                <button class="btn btn-outline-primary w-100" type="submit" @disabled(! $integrationEnabled)>Run safe connection check</button>
            </form>
            @endcan
        </section>
    </div>
@endsection
