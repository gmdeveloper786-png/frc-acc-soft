@extends('layouts.app') @section('title','Audit Entry #'.$log->id) @section('eyebrow','Audit') @section('page-title','Audit Entry #'.$log->id)
@section('page-description','Read-only. Audit entries cannot be edited or deleted.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ url()->previous() === url()->current() ? route('audit-logs.index') : url()->previous() }}">Back to audit log</a>@endsection
@section('content')
@php
    $logResult = $presenter::result($log->event);
@endphp
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $presenter::moduleLabel($presenter::module($log->event)) }}</span><h2><code>{{ $log->event }}</code></h2></div><span class="status-badge {{ $logResult === 'Refused' ? 'danger' : 'success' }}">{{ $logResult }}</span></div>
    <div class="table-responsive"><table class="table accounting-table"><tbody>
        <tr><th scope="row">Time</th><td>{{ $log->created_at?->format('d M Y H:i:s') }}</td></tr>
        <tr><th scope="row">Actor</th><td>{{ $log->actor?->name ?? 'System' }}@if($log->actor?->email)<span class="table-subtext">{{ $log->actor->email }}</span>@endif</td></tr>
        <tr><th scope="row">IP address</th><td>{{ $log->ip_address ?? '—' }}</td></tr>
        <tr><th scope="row">Browser</th><td>{{ $presenter->userAgent($log) ?? '—' }}</td></tr>
        <tr><th scope="row">Record</th><td>{{ $presenter->record($log) ?? '—' }}@if($log->auditable_type)<span class="table-subtext"><a href="{{ route('audit-logs.index', ['record_type' => $log->auditable_type, 'record_id' => $log->auditable_id]) }}">All entries for this record</a></span>@endif</td></tr>
        <tr><th scope="row">Branch</th><td>{{ $log->branch?->name ?? 'Not branch-specific' }}</td></tr>
    </tbody></table></div>
</section>
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Recorded details</span><h2>Properties</h2></div></div>
    @if($salaryMasked)<div class="alert alert-warning">Salary and payroll amounts are hidden because you do not have permission to view sensitive payroll data.</div>@endif
    @if($properties === [])
        <x-empty-state title="No details recorded" description="This action was recorded without additional properties." />
    @else
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Detail</th><th>Value</th></tr></thead><tbody>
        @foreach($properties as $property)
            <tr><th scope="row">{{ $property['label'] }}</th><td>@if($property['masked'])<span class="status-badge neutral">{{ $property['value'] }}</span>@else<span style="white-space: pre-wrap; word-break: break-word;">{{ $property['value'] }}</span>@endif</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</section>
@endsection
