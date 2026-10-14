@extends('layouts.app') @section('title','Payroll Runs') @section('eyebrow','Payroll') @section('page-title','Payroll Runs')
@section('page-description','One payroll run per branch and accounting period: Draft → Validated → Submitted → Approved → Posted → Paid.')
@section('page-actions')
<div class="d-flex gap-2">
    @can('payroll.import')<a class="btn btn-outline-primary" href="{{ route('payroll-imports.index') }}">Import from spreadsheet</a>@endcan
    @can('payroll.manage')<a class="btn btn-primary" href="{{ route('payroll-runs.create') }}">+ New payroll run</a>@endcan
</div>
@endsection
@section('content')
@include('payroll._policy-banner')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="accounting_period_id"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('accounting_period_id')==$period->id)>{{ $period->name }}</option>@endforeach</select>
        <select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Enums\PayrollRunStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('payroll-runs.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Run</th><th>Period</th><th>Branch</th><th>Source</th><th>Status</th><th class="text-end">Employees</th>@if($sensitive)<th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th>@endif<th>Actions</th></tr></thead><tbody>
    @forelse($runs as $run)
        <tr>
            <td><code>#{{ $run->id }}</code></td>
            <td>{{ $run->period->name }}<span class="table-subtext">{{ $run->period->start_date->format('d M Y') }} – {{ $run->period->end_date->format('d M Y') }}</span></td>
            <td>{{ $run->branch->name }}</td>
            <td>{{ ucfirst($run->source) }}</td>
            <td><span class="status-badge {{ $run->status->badge() }}">{{ $run->status->label() }}</span></td>
            <td class="text-end font-monospace">{{ $run->lines_count }}</td>
            @if($sensitive)
            <td class="text-end font-monospace">{{ number_format((float)$run->gross_amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$run->total_deductions,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$run->net_amount,2) }}</td>
            @endif
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-runs.show',$run) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="{{ $sensitive ? 10 : 7 }}"><x-empty-state title="No payroll runs found" description="Create a draft run from the salary structures or from a reviewed spreadsheet import." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $runs->links() }}
</section>
@endsection
