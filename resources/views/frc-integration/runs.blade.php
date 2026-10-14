@extends('layouts.app')
@section('title', 'FRC Sync History')
@section('eyebrow', 'FRC fee integration')
@section('page-title', 'Sync History')
@section('page-description', 'Every FRC enrollment and payment sync run with its counters. Error text is sanitised and never contains the token or source data.')
@section('content')
@php $fmt = fn ($date) => $date ? $date->format('d M Y H:i:s') : '—'; @endphp
<div class="surface-card">
    <form class="filter-bar" method="GET">
        <select class="form-select" name="entity"><option value="">All entities</option>@foreach(\App\Services\Frc\FinancialSync\FrcFinancialApi::ENTITIES as $option)<option value="{{ $option }}" @selected(request('entity') === $option)>{{ ucfirst($option) }}</option>@endforeach</select>
        <select class="form-select" name="status"><option value="">All statuses</option>@foreach(['running', 'completed', 'failed'] as $option)<option value="{{ $option }}" @selected(request('status') === $option)>{{ ucfirst($option) }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
    </form>
    <div class="table-responsive"><table class="table accounting-table align-middle">
        <thead><tr><th>Run</th><th>Entity</th><th>Mode</th><th>Status</th><th>Started</th><th>Finished</th><th>By</th><th class="text-end">Pages</th><th class="text-end">Received</th><th class="text-end">New</th><th class="text-end">Updated</th><th class="text-end">Unchanged</th><th class="text-end">Failed</th></tr></thead>
        <tbody>
        @forelse($runs as $run)
            <tr>
                <td class="font-monospace">#{{ $run->id }}</td>
                <td>{{ ucfirst($run->entity) }}</td>
                <td>{{ ucfirst($run->mode) }}</td>
                <td><span class="status-badge {{ ['completed' => 'success', 'failed' => 'danger'][$run->status] ?? 'pending' }}">{{ ucfirst($run->status) }}</span></td>
                <td>{{ $fmt($run->started_at) }}</td>
                <td>{{ $fmt($run->finished_at) }}</td>
                <td>{{ $run->trigger?->name ?? 'Console' }}</td>
                @foreach(['pages', 'fetched', 'created', 'updated', 'unchanged', 'failed'] as $counter)<td class="text-end font-monospace">{{ number_format($run->{$counter}) }}</td>@endforeach
            </tr>
            @if($run->error)<tr><td></td><td colspan="12"><span class="text-danger">{{ $run->error }}</span></td></tr>@endif
        @empty
            <tr><td colspan="13"><x-empty-state title="No sync runs yet" description="Nothing has been synced from the FRC software. The payments and enrollments endpoints await approval." /></td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $runs->links() }}
</div>
@endsection
