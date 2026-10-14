@extends('layouts.app') @section('title','Accounting Setup Readiness') @section('eyebrow','Accounting Setup') @section('page-title','Accounting Setup Readiness')
@section('page-description','Live status of everything financial posting depends on. Each status is read from the database; nothing is assumed.')
@section('page-actions')@can('account_mappings.view')<a class="btn btn-outline-primary" href="{{ route('account-mappings.index') }}">GL Mapping</a>@endcan @endsection
@section('content')
@if($readyCount===$checks->count())
    <div class="alert alert-success">All {{ $checks->count() }} setup checks pass. Posting still requires each transaction's own approval workflow.</div>
@else
    <div class="alert alert-danger"><strong>Not ready for financial posting.</strong> {{ $readyCount }} of {{ $checks->count() }} checks pass. Resolve the items marked "Action required" below.</div>
@endif
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Pre-posting checks</span><h2>Setup status</h2></div><span class="status-badge {{ $readyCount===$checks->count()?'success':'danger' }}">{{ $readyCount }} / {{ $checks->count() }} ready</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Check</th><th>Status</th><th>Details</th><th>Action</th></tr></thead><tbody>
    @foreach($checks as $check)
        <tr>
            <td><strong>{{ $check['label'] }}</strong></td>
            <td><span class="status-badge {{ $check['ready']?'success':'danger' }}">{{ $check['ready']?'Ready':'Action required' }}</span></td>
            <td>{{ $check['summary'] }}@foreach($check['issues'] as $issue)<span class="d-block text-danger small">{{ $issue }}</span>@endforeach</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route($check['route']) }}">{{ $check['action'] }}</a></td>
        </tr>
    @endforeach
    </tbody></table></div>
</section>
@endsection
