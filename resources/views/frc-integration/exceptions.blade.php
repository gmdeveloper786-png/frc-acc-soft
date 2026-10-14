@extends('layouts.app')
@section('title', 'FRC Sync Exceptions')
@section('eyebrow', 'FRC fee integration')
@section('page-title', 'Sync Exceptions')
@section('page-description', 'Records that were skipped, left unmapped or changed after posting. Messages are sanitised and never contain source payloads.')
@section('content')
@php $fmt = fn ($date) => $date ? $date->format('d M Y H:i') : '—'; @endphp
<div class="surface-card">
    <form class="filter-bar" method="GET">
        <select class="form-select" name="code"><option value="">All codes</option>@foreach(\App\Services\Frc\FinancialSync\FrcFinancialSyncService::EXCEPTION_CODES as $option)<option value="{{ $option }}" @selected(request('code') === $option)>{{ str($option)->headline() }}</option>@endforeach</select>
        <select class="form-select" name="entity"><option value="">All entities</option>@foreach(\App\Services\Frc\FinancialSync\FrcFinancialApi::ENTITIES as $option)<option value="{{ $option }}" @selected(request('entity') === $option)>{{ ucfirst($option) }}</option>@endforeach</select>
        <select class="form-select" name="state">@foreach(['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label)<option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
    </form>
    <div class="table-responsive"><table class="table accounting-table align-middle">
        <thead><tr><th>Raised</th><th>Entity</th><th>FRC id</th><th>Code</th><th>Message</th><th>Run</th><th>State</th><th></th></tr></thead>
        <tbody>
        @forelse($exceptions as $exception)
            <tr>
                <td>{{ $fmt($exception->updated_at) }}</td>
                <td>{{ ucfirst($exception->entity) }}</td>
                <td class="font-monospace">{{ $exception->external_id ?? '—' }}</td>
                <td><code>{{ $exception->code }}</code></td>
                <td>{{ $exception->message }}</td>
                <td>{{ $exception->frc_sync_run_id ? '#'.$exception->frc_sync_run_id : '—' }}</td>
                <td>@if($exception->resolved_at)<span class="status-badge success">Resolved</span><span class="table-subtext">{{ $fmt($exception->resolved_at) }} {{ $exception->resolver?->name ?? 'automatically' }}</span>@else<span class="status-badge danger">Open</span>@endif</td>
                <td>
                    @if(! $exception->resolved_at)
                        @can('frc_integration.sync')
                            <form method="POST" action="{{ route('frc-integration.exceptions.resolve', $exception) }}" onsubmit="return window.confirm('Mark this exception as resolved?')">@csrf<button class="btn btn-sm btn-outline-primary">Mark resolved</button></form>
                        @endcan
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8"><x-empty-state title="No exceptions" description="No sync exceptions match these filters." /></td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $exceptions->links() }}
</div>
@endsection
