@extends('layouts.app')
@section('title', 'Fiscal Years') @section('eyebrow', 'Accounting Setup') @section('page-title', 'Fiscal Years')
@section('page-description', 'Financial years run from January to December. Status changes are controlled and audited.')
@section('page-actions') @can('fiscal_years.manage')<a class="btn btn-primary" href="{{ route('fiscal-years.create') }}">+ New fiscal year</a>@endcan @endsection
@section('content')
<section class="surface-card"><div class="card-heading"><h2>Fiscal year structure</h2><span class="status-badge neutral">{{ $fiscalYears->total() }} records</span></div>
<div class="table-responsive"><table class="table accounting-table align-middle"><thead><tr><th>Name</th><th>Start</th><th>End</th><th>Periods</th><th>Status</th><th>Current</th><th>Actions</th></tr></thead><tbody>
@forelse($fiscalYears as $year)<tr><td><strong>{{ $year->name }}</strong></td><td>{{ $year->start_date->format('d M Y') }}</td><td>{{ $year->end_date->format('d M Y') }}</td><td>{{ $year->periods_count }}</td><td><span class="status-badge {{ $year->status->value === 'open' ? 'success' : ($year->status->value === 'closed' ? 'danger' : 'neutral') }}">{{ ucfirst($year->status->value) }}</span></td><td>{{ $year->is_current ? 'Yes' : '—' }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('fiscal-years.show',$year) }}">View</a>@can('fiscal_years.close')@if($year->status->value !== 'draft') <a class="btn btn-sm btn-outline-primary" href="{{ route('fiscal-years.closing',$year) }}">Year-end closing</a>@endif @endcan</td></tr>
@empty<tr><td colspan="7"><x-empty-state title="No fiscal years" description="Create the first controlled fiscal year structure." /></td></tr>@endforelse
</tbody></table></div>{{ $fiscalYears->links() }}</section>
@endsection
