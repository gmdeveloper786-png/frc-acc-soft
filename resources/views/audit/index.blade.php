@extends('layouts.app') @section('title','Audit Log') @section('eyebrow','Audit') @section('page-title','Audit Log')
@section('page-description','A permanent, read-only record of sensitive actions. Entries cannot be edited or deleted.')
@section('content')
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Sensitive financial actions</span><h2>Quick filters</h2></div></div>
    <div class="d-flex flex-wrap gap-2">
        @foreach($quickFilters as $quickKey => $quickFilter)
            <a class="btn btn-sm {{ request('quick') === $quickKey ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('audit-logs.index', ['quick' => $quickKey]) }}">{{ $quickFilter['label'] }}</a>
        @endforeach
        <a class="btn btn-sm {{ request('result') === 'refused' && ! request('quick') ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('audit-logs.index', ['result' => 'refused']) }}">Refused attempts</a>
    </div>
</section>
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        @if(request('quick'))<input type="hidden" name="quick" value="{{ request('quick') }}">@endif
        <input class="form-control @error('search') is-invalid @enderror" name="search" value="{{ request('search') }}" placeholder="Search event or details" aria-label="Search event or details">
        <input class="form-control @error('from') is-invalid @enderror" type="date" name="from" value="{{ request('from') }}" aria-label="From date">
        <input class="form-control @error('to') is-invalid @enderror" type="date" name="to" value="{{ request('to') }}" aria-label="To date">
        <select class="form-select" name="actor_id" aria-label="Actor"><option value="">All actors</option>@foreach($options['actors'] as $actor)<option value="{{ $actor->id }}" @selected(request('actor_id')==$actor->id)>{{ $actor->name }}</option>@endforeach</select>
        <select class="form-select" name="module" aria-label="Module"><option value="">All modules</option>@foreach($options['modules'] as $module)<option value="{{ $module }}" @selected(request('module')===$module)>{{ $presenter::moduleLabel($module) }}</option>@endforeach</select>
        <select class="form-select" name="event" aria-label="Action"><option value="">All actions</option>@foreach($options['events'] as $event)<option value="{{ $event }}" @selected(request('event')===$event)>{{ $event }}</option>@endforeach</select>
        <select class="form-select" name="record_type" aria-label="Record type"><option value="">Any record type</option>@foreach($options['record_types'] as $recordType)<option value="{{ $recordType }}" @selected(request('record_type')===$recordType)>{{ $presenter::recordType($recordType) }}</option>@endforeach</select>
        <input class="form-control @error('record_id') is-invalid @enderror" type="number" min="1" name="record_id" value="{{ request('record_id') }}" placeholder="Record ID" aria-label="Record ID">
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($options['branches'] as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="result" aria-label="Result"><option value="">Any result</option><option value="recorded" @selected(request('result')==='recorded')>Recorded</option><option value="refused" @selected(request('result')==='refused')>Refused</option></select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('audit-logs.index') }}">Reset</a>
    </form>
    @error('to')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Time</th><th>Actor</th><th>Module</th><th>Action</th><th>Record</th><th>Branch</th><th>Result</th><th>Actions</th></tr></thead><tbody>
    @forelse($logs as $log)
        @php
            $logResult = $presenter::result($log->event);
        @endphp
        <tr>
            <td>{{ $log->created_at?->format('d M Y H:i:s') }}</td>
            <td>{{ $log->actor?->name ?? 'System' }}@if($log->ip_address)<span class="table-subtext">{{ $log->ip_address }}</span>@endif</td>
            <td>{{ $presenter::moduleLabel($presenter::module($log->event)) }}</td>
            <td><code>{{ $log->event }}</code></td>
            <td>{{ $presenter->record($log) ?? '—' }}</td>
            <td>{{ $log->branch?->name ?? '—' }}</td>
            <td><span class="status-badge {{ $logResult === 'Refused' ? 'danger' : 'success' }}">{{ $logResult }}</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('audit-logs.show', $log) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No audit entries found" description="No recorded action matches these filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $logs->links() }}
</section>
@endsection
