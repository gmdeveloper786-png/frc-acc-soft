@extends('layouts.app') @section('title',$title) @section('eyebrow','Payroll reports') @section('page-title',$title)
@section('page-description','Stored payroll amounts only, limited to the branches you can access. This report posts nothing.')
@section('page-actions')
<div class="d-flex gap-2">
    @if($canExport && $table)
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $formatLabel)<a class="btn btn-outline-primary" href="{{ route('payroll-reports.export', ['report' => $report, 'format' => $format, ...request()->except('page')]) }}">{{ $formatLabel }}</a>@endforeach
    @endif
    <a class="btn btn-outline-primary" href="{{ route('payroll-reports.index') }}">All payroll reports</a>
</div>
@endsection
@section('content')
@unless($sensitive)
    <div class="alert alert-warning" role="alert"><strong>Salary amounts are hidden.</strong> Your account does not hold the sensitive payroll permission; this report shows workflow information only.</div>
@endunless
<div class="surface-card mb-4"><form class="row g-3" method="GET" action="{{ route('payroll-reports.show', $report) }}">
    @foreach($filters as $filter)
        <div class="col-md-3"><label class="form-label" for="filter_{{ $filter['name'] }}">{{ $filter['label'] }}</label>
            <select class="form-select" id="filter_{{ $filter['name'] }}" name="{{ $filter['name'] }}">@foreach($filter['options'] as $value => $optionLabel)<option value="{{ $value }}" @selected((string) $value === $filter['value'])>{{ $optionLabel }}</option>@endforeach</select></div>
    @endforeach
    <div class="col-12"><button class="btn btn-primary">Generate report</button></div>
</form></div>

@if(! $table)
    <section class="surface-card"><x-empty-state title="{{ $prompt ?? 'Nothing selected' }}" description="Choose from the filters above. Only records of branches you can access are offered." /></section>
@else
    @php
        $numeric = ['money' => 2, 'quantity' => 4, 'percent' => 2, 'number' => 0];
    @endphp
    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">@foreach($table->meta as $metaLabel => $metaValue){{ $metaLabel }}: {{ $metaValue }}@unless($loop->last) · @endunless @endforeach</span><h2>{{ $table->title }}</h2></div></div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr>@foreach($table->columns as $column)<th @class(['text-end' => isset($numeric[$column['type'] ?? 'text'])])>{{ $column['label'] }}</th>@endforeach</tr></thead><tbody>
        @forelse($table->rows as $row)
            <tr @class(['fw-semibold' => isset($row['_style'])])>
            @foreach($table->columns as $column)
                @php
                    $value = $row[$column['key']] ?? null;
                    $decimals = $numeric[$column['type'] ?? 'text'] ?? null;
                @endphp
                @if($decimals !== null)
                    <td class="text-end font-monospace">{{ is_numeric($value) ? number_format((float) $value, $decimals) : '—' }}</td>
                @else
                    <td @if($loop->first && ($row['_indent'] ?? 0) > 0) style="padding-left: {{ 12 + 16 * $row['_indent'] }}px" @endif>{{ $value }}</td>
                @endif
            @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($table->columns) }}"><x-empty-state title="No payroll records for this selection" description="There is nothing to report for the chosen branch, period and status. No figure is shown in place of a missing one." /></td></tr>
        @endforelse
        </tbody></table></div>
        @if($paginator){{ $paginator->links() }}@endif
        @if($table->note)<p class="card-copy">{{ $table->note }}</p>@endif
    </section>
@endif
@endsection
