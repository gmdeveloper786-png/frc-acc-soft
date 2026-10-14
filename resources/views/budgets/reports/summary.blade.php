@php
    $isService = $mode === 'service';
    $heading = $isService ? 'Service Budget Summary' : 'Branch Budget Summary';
@endphp
@extends('layouts.app') @section('title',$heading) @section('eyebrow','Budget reports') @section('page-title',$heading)
@section('page-description', $isService ? 'Approved service budgets against posted ledger activity tagged with each service.' : 'Approved whole-branch budgets against posted ledger activity of each branch you can access.')
@section('page-actions')
<div class="d-flex gap-2">
    @can('budget_reports.export') @if($year)
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $formatLabel)<a class="btn btn-outline-primary" href="{{ route($isService ? 'budget-reports.service-summary.export' : 'budget-reports.branch-summary.export', ['format' => $format, ...request()->query()]) }}">{{ $formatLabel }}</a>@endforeach
    @endif @endcan
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
</div>
@endsection
@section('content')
@include('budgets.reports._filters', ['requireBranch' => false, 'showService' => $isService])

@if(! $year)
    <section class="surface-card"><x-empty-state title="Select a fiscal year" description="No current fiscal year is set. Choose the fiscal year to summarise." /></section>
@elseif(collect($rows)->whereNotNull('budget')->isEmpty())
    <section class="surface-card"><x-empty-state title="No approved {{ $isService ? 'service' : 'branch' }} budget for {{ $year->name }}" description="There is no approved {{ $isService ? 'service' : 'whole-branch' }} budget for this selection, so no figures are shown. Draft, submitted and rejected budgets are not reported." /></section>
@else
    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">{{ $year->name }} · warning from {{ $warningPercentage }}% (configured) · no transaction is blocked</span><h2>{{ $heading }}</h2></div></div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Branch</th>@if($isService)<th>Service</th>@endif<th>Budget</th><th>Type</th><th class="text-end">Budget allocation</th><th class="text-end">Actual</th><th class="text-end">Remaining</th><th class="text-end">Variance</th><th class="text-end">Utilisation %</th><th>Status</th><th class="text-end">PO commitments (not in Actual)</th></tr></thead><tbody>
        @foreach($rows as $row)
            @if($row['budget'] === null)
                <tr><td>{{ $row['branch']->name }}</td><td colspan="{{ $isService ? 10 : 9 }}"><span class="status-badge neutral">No approved {{ $isService ? 'service' : 'whole-branch' }} budget</span></td></tr>
            @else
                @foreach(['expense' => 'Expenses', 'income' => 'Income'] as $type => $label)
                    @if($row['report']['totals'][$type]['has_lines'])
                        <tr><td>{{ $row['branch']->name }}</td>@if($isService)<td>{{ $row['budget']->service?->name ?? '—' }}</td>@endif
                            <td>@can('budgets.view')<a href="{{ route('budgets.show',$row['budget']) }}">{{ $row['budget']->name }}</a>@else{{ $row['budget']->name }}@endcan<span class="table-subtext">v{{ $row['budget']->version }} · {{ $row['report']['from']->name }} to {{ $row['report']['to']->name }}</span></td>
                            <td>{{ $label }}</td>
                            @include('budgets.reports._figures', ['figures' => $row['report']['totals'][$type]])</tr>
                    @endif
                @endforeach
            @endif
        @endforeach
        </tbody></table></div>
        <p class="card-copy">Each total counts every budgeted ledger once. Actual comes from posted journals only; purchase order commitments are shown separately and are never added to Actual.</p>
    </section>
@endif
@endsection
