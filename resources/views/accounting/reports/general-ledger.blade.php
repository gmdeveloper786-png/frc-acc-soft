@extends('layouts.app')
@section('title', 'General Ledger')
@section('page-title', 'General Ledger')
@section('page-description', 'Balances are derived only from posted journal lines. A service filter shows a subset of activity, not a separate entity ledger.')
@section('page-actions')
    @if($report && $report['rows']->total() > 0)@can('reports.export')@include('accounting.reports._export-links', ['exportRoute' => 'reports.general-ledger.export'])@endcan @endif
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
@endsection
@section('content')
<div class="surface-card mb-4">
    <form class="row g-3" method="GET">
        <div class="col-md-3"><label class="form-label">Fiscal Year</label><select class="form-select" name="fiscal_year_id" required><option value="">Select year</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(request('fiscal_year_id') == $year->id)>{{ $year->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Account</label><select class="form-select" name="account_id" required><option value="">Select ledger</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(request('account_id') == $account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="{{ request('from') }}" required></div>
        <div class="col-md-3"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="{{ request('to') }}" required></div>
        <div class="col-md-4"><label class="form-label">Branch</label><select class="form-select" name="branch_id"><option value="">All authorized branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">Service</label><select class="form-select" name="service_id"><option value="">All activity</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(request('service_id') == $service->id)>{{ $service->name }}</option>@endforeach</select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary">Generate Ledger</button></div>
    </form>
</div>
@if($report)
<div class="surface-card">
    <div class="d-flex flex-wrap justify-content-between gap-3">
        <div><strong>{{ $report['account']->code }} — {{ $report['account']->name }}</strong><small class="d-block text-muted">{{ $report['fiscal_year']->name }}</small></div>
        <div><strong>Opening: {{ $report['opening_balance'] }} {{ strtoupper($report['opening_side']) }}</strong><strong class="ms-4">Closing: {{ $report['closing_balance'] }} {{ strtoupper($report['closing_side']) }}</strong></div>
    </div>
    <div class="table-responsive mt-3"><table class="table accounting-table"><thead><tr><th>Date</th><th>Voucher</th><th>Reference</th><th>Narration</th><th>Branch</th><th>Service</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Running Balance</th></tr></thead><tbody>
        @forelse($report['rows'] as $row)<tr><td>{{ $row['date']->format('d M Y') }}</td><td>{{ $row['voucher_number'] }}</td><td>{{ $row['reference'] ?: '—' }}</td><td>{{ $row['narration'] }}</td><td>{{ $row['branch'] }}</td><td>{{ $row['service'] ?: '—' }}</td><td class="text-end font-monospace">{{ $row['debit'] }}</td><td class="text-end font-monospace">{{ $row['credit'] }}</td><td class="text-end font-monospace">{{ $row['running_balance'] }} {{ strtoupper($row['running_side']) }}</td></tr>
        @empty<tr><td colspan="9" class="text-center">No posted activity in this range.</td></tr>@endforelse
    </tbody></table></div>
    {{ $report['rows']->links() }}
</div>
@endif
@endsection
