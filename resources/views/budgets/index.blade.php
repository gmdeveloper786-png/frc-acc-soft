@extends('layouts.app') @section('title','Budgets') @section('eyebrow','Budget management') @section('page-title','Budgets')
@section('page-description','Planning records per fiscal year, branch and service. A budget never posts to the General Ledger and never blocks a transaction.')
@section('page-actions')@can('budgets.create')<a class="btn btn-primary" href="{{ route('budgets.create') }}">+ New budget</a>@endcan @endsection
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Budget name" aria-label="Search budget name">
        <select class="form-select" name="fiscal_year_id" aria-label="Fiscal year"><option value="">All fiscal years</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(request('fiscal_year_id')==$year->id)>{{ $year->name }}</option>@endforeach</select>
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="service_id" aria-label="Service"><option value="">All scopes</option><option value="all" @selected(request('service_id')==='all')>Whole branch only</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(request('service_id')==$service->id)>{{ $service->name }}</option>@endforeach</select>
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach(\App\Enums\BudgetStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('budgets.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Budget</th><th>Fiscal year</th><th>Branch</th><th>Service</th><th>Version</th><th>Status</th><th class="text-end">Total</th><th>Actions</th></tr></thead><tbody>
    @forelse($budgets as $budget)
        <tr>
            <td>{{ $budget->name }}<span class="table-subtext">Prepared by {{ $budget->creator?->name ?? '—' }}</span></td>
            <td>{{ $budget->fiscalYear->name }}</td>
            <td>{{ $budget->branch->name }}</td>
            <td>{{ $budget->service?->name ?? 'Whole branch' }}</td>
            <td>v{{ $budget->version }}</td>
            <td><span class="status-badge {{ $budget->status->badge() }}">{{ $budget->status->label() }}</span></td>
            <td class="text-end font-monospace">{{ $appSettings['currency_code'] }} {{ number_format((float)$budget->total_amount,2) }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('budgets.show',$budget) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No budgets found" description="Create a budget or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $budgets->links() }}
</section>
@endsection
