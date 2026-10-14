@extends('layouts.app')
@section('title', 'Trial Balance')
@section('page-title', 'Trial Balance')
@section('page-description', 'Parent balances roll up from ledger accounts; grand totals count each postable ledger once.')
@section('page-actions')
    @if($report && $report['rows']->isNotEmpty())@can('reports.export')@include('accounting.reports._export-links', ['exportRoute' => 'reports.trial-balance.export'])@endcan @endif
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
@endsection
@section('content')
<div class="surface-card mb-4"><form class="row g-3" method="GET">
    <div class="col-md-4"><label class="form-label">Fiscal Year</label><select class="form-select" name="fiscal_year_id" required><option value="">Select year</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(request('fiscal_year_id') == $year->id)>{{ $year->name }}</option>@endforeach</select></div>
    <div class="col-md-4"><label class="form-label">As of</label><input class="form-control" type="date" name="as_of" value="{{ request('as_of') }}" required></div>
    <div class="col-md-4"><label class="form-label">Branch</label><select class="form-select" name="branch_id"><option value="">All authorized branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
    <div class="col-12"><button class="btn btn-primary">Generate Trial Balance</button></div>
</form></div>
@if($report)
<div class="surface-card">
    <h2 class="section-title">{{ $report['fiscal_year']->name }} as of {{ request('as_of') }}</h2>
    @unless($report['balanced'])<div class="alert alert-danger">Accounting integrity warning: Trial Balance difference is {{ $report['difference'] }}.</div>@endunless
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Account Code</th><th>Account Name</th><th>Level</th><th class="text-end">Debit Balance</th><th class="text-end">Credit Balance</th></tr></thead><tbody>
        @foreach($report['rows'] as $row)<tr><td>{{ $row['account']->code }}</td><td style="padding-left: {{ match($row['account']->level->value) {'class' => 0, 'group' => 16, 'sub_group' => 32, default => 48} }}px">{{ $row['account']->name }}</td><td>{{ str($row['account']->level->value)->headline() }}</td><td class="text-end font-monospace">{{ $row['debit'] }}</td><td class="text-end font-monospace">{{ $row['credit'] }}</td></tr>@endforeach
    </tbody><tfoot><tr><th colspan="3">Grand totals (ledgers only)</th><th class="text-end font-monospace">{{ $report['total_debit'] }}</th><th class="text-end font-monospace">{{ $report['total_credit'] }}</th></tr></tfoot></table></div>
</div>
@endif
@endsection
