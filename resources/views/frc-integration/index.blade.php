@extends('layouts.app')
@section('title', 'FRC Financial Integration')
@section('eyebrow', 'FRC fee integration')
@section('page-title', 'FRC Financial Integration')
@section('page-description', 'Read-only mirror of FRC enrollments and fee payments. Syncing never creates journals; posting is a separate, authorised step.')
@section('page-actions')
    @can('frc_integration.sync')
        <form method="POST" action="{{ route('frc-integration.check') }}" class="d-inline">@csrf<button class="btn btn-outline-primary">Check connection</button></form>
    @endcan
@endsection
@section('content')
@php $fmt = fn ($date) => $date ? $date->format('d M Y H:i') : '—'; @endphp

@if($awaitingApproval)
    <div class="alert alert-warning" role="alert"><strong>FRC endpoints awaiting approval.</strong> The FRC Management Software answered HTTP 404 or 403 for the payments or enrollments endpoint. These read-only endpoints are a proposal that the FRC owner has not approved yet, so nothing can be imported until they are deployed and the integration token is re-issued with the new abilities.</div>
@endif

@unless($configuration['enabled'] && $configuration['base_url'] && $configuration['token'])
    <div class="alert alert-danger" role="alert">
        FRC API configuration is incomplete:
        {{ collect(['enabled' => 'integration disabled', 'base_url' => 'base URL missing', 'token' => 'token missing'])->filter(fn ($label, $key) => ! $configuration[$key])->implode(', ') }}.
        Configure it securely on the server.
    </div>
@endunless

@if($neverSynced)
    <x-empty-state title="Nothing has been synced yet" description="No FRC enrollments or payments have been mirrored. An authorised user can start a manual full sync below once the FRC endpoints are available." />
@endif

<div class="kpi-grid">
    <x-kpi-card label="Mirrored enrollments" value="{{ number_format($counts['enrollments']) }}" icon="E" tone="navy" status="Your branches" description="Enrollment fee agreements in the mirror"/>
    <x-kpi-card label="Mirrored payments" value="{{ number_format($counts['payments']) }}" icon="P" tone="teal" status="Your branches" description="Payments of every status, including pending verification"/>
    <x-kpi-card label="Unmapped payments" value="{{ number_format($counts['unmapped']) }}" icon="U" tone="amber" status="{{ $counts['unmapped'] ? 'Action needed' : 'None' }}" description="Branch or service not found locally"/>
    <x-kpi-card label="Open exceptions" value="{{ number_format($counts['open_exceptions']) }}" icon="X" tone="rose" status="{{ $counts['needs_review'] }} need review" description="Invalid, unmapped or changed-after-posting records"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Per entity</span><h2>Synchronisation status</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table align-middle">
        <thead><tr><th>Entity</th><th>Endpoint</th><th>Last successful sync</th><th>Cursor</th><th>Last run</th></tr></thead>
        <tbody>
        @foreach($entities as $entity => $info)
            <tr>
                <td class="fw-semibold">{{ ucfirst($entity) }}</td>
                <td><code>{{ $info['endpoint'] }}</code></td>
                <td>@if($info['last_success']){{ $fmt($info['last_success']->finished_at) }} <span class="table-subtext">{{ $info['last_success']->mode }} · {{ $info['last_success']->fetched }} received</span>@else<span class="table-subtext">Never</span>@endif</td>
                <td>@if($info['last_success']?->cursor_to)<span class="font-monospace">{{ $info['last_success']->cursor_to->format('d M Y H:i:s') }} / #{{ $info['last_success']->cursor_to_id ?? '—' }}</span>@else<span class="table-subtext">From the beginning</span>@endif</td>
                <td>
                    @if($info['last_run'])
                        <span class="status-badge {{ ['completed' => 'success', 'failed' => 'danger'][$info['last_run']->status] ?? 'pending' }}">{{ ucfirst($info['last_run']->status) }}</span>
                        <span class="table-subtext">{{ $fmt($info['last_run']->started_at) }}</span>
                        @if($info['last_run']->error)<div class="table-subtext">{{ $info['last_run']->error }}</div>@endif
                    @else<span class="table-subtext">No runs</span>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>

@can('frc_integration.sync')
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Manual only — nothing is scheduled</span><h2>Run a sync</h2></div></div>
    <p class="card-copy">Enrollments are read first, then payments. An incremental sync continues from the last successful cursor; a full sync re-reads everything. Records are matched by their FRC id, so repeating a sync never creates duplicates. A change to an already posted payment only flags it for review.</p>
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" action="{{ route('frc-integration.sync') }}" onsubmit="return window.confirm('Run an incremental FRC sync now?')">@csrf<input type="hidden" name="mode" value="incremental"><input type="hidden" name="confirm" value="1"><button class="btn btn-primary">Incremental sync</button></form>
        <form method="POST" action="{{ route('frc-integration.sync') }}" onsubmit="return window.confirm('Run a manual FULL FRC sync? Every enrollment and payment will be re-read.')">@csrf<input type="hidden" name="mode" value="full"><input type="hidden" name="confirm" value="1"><button class="btn btn-outline-primary">Manual full sync</button></form>
    </div>
</section>
@endcan

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Shortcuts</span><h2>Mirror screens</h2></div></div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="{{ route('frc-integration.payments.index') }}">Source payments</a>
        <a class="btn btn-outline-primary" href="{{ route('frc-integration.payments.unmapped') }}">Unmapped payments ({{ $counts['unmapped'] }})</a>
        <a class="btn btn-outline-primary" href="{{ route('frc-integration.runs.index') }}">Sync history</a>
        <a class="btn btn-outline-primary" href="{{ route('frc-integration.exceptions.index') }}">Sync exceptions ({{ $counts['open_exceptions'] }})</a>
        <a class="btn btn-outline-primary" href="{{ route('frc-integration.reconciliation') }}">Reconciliation</a>
    </div>
</section>
@endsection
