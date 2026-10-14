@extends('layouts.app') @section('title','Depreciation Runs') @section('eyebrow','Fixed Assets') @section('page-title','Depreciation Runs')
@section('page-description','Monthly straight-line depreciation per branch and accounting period. A run posts one journal after separate approval.')
@section('page-actions')@can('depreciation.prepare')<a class="btn btn-primary" href="{{ route('depreciation-runs.create') }}">+ New depreciation run</a>@endcan @endsection
@section('content')
@include('assets._policy-banner')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Accounting period name">
        <select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Enums\DepreciationRunStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('depreciation-runs.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Run</th><th>Period</th><th>Branch</th><th>Prepared by</th><th class="text-end">Assets</th><th>Status</th><th class="text-end">Total depreciation</th><th>Actions</th></tr></thead><tbody>
    @forelse($runs as $run)
        <tr>
            <td><code>#{{ $run->id }}</code></td>
            <td>{{ $run->accountingPeriod->name }}<span class="table-subtext">{{ $run->accountingPeriod->start_date->format('d M Y') }} – {{ $run->accountingPeriod->end_date->format('d M Y') }}</span></td>
            <td>{{ $run->branch->name }}</td>
            <td>{{ $run->creator?->name ?? '—' }}</td>
            <td class="text-end">{{ $run->lines_count }}</td>
            <td><span class="status-badge {{ $run->status->badge() }}">{{ $run->status->label() }}</span>@if($run->rejection_reason && $run->status===\App\Enums\DepreciationRunStatus::Draft)<span class="table-subtext">Returned for correction</span>@endif</td>
            <td class="text-end font-monospace">{{ number_format((float)$run->total_amount,2) }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('depreciation-runs.show',$run) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No depreciation runs found" description="Prepare a run for an open accounting period, or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $runs->links() }}
</section>
@endsection
