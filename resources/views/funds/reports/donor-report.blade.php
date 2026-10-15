@extends('layouts.app')
@section('title', 'Donor Report')
@section('eyebrow', 'Funds & Grants')
@section('page-title', 'Donor Report'.($report ? ': '.$report['fund']->label() : ''))
@section('page-description', 'Income received and expenditure charged to one grant fund, with utilisation and remaining balance.')
@section('page-actions')
    @if($report)
        @include('funds.reports._exports', ['available' => true, 'exportRoute' => fn ($format) => route('fund-reports.donor-report.export', ['fund' => $report['fund'], 'format' => $format] + request()->query())])
    @endif
    <a class="btn btn-outline-primary" href="{{ route('fund-reports.grant-utilisation', request()->only(['branch_id', 'as_of'])) }}">Back</a>
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @include('funds.reports._filters', ['showPeriod' => false, 'showAsOf' => true, 'showFund' => false])
    @php($money = fn ($value) => $value === null ? 'Not recorded' : number_format((float) $value, 2))
    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">{{ $report['scope']->label }}</span><h2>As of {{ \Illuminate\Support\Carbon::parse($report['as_of'])->format('d M Y') }}</h2></div></div>
        <div class="row g-3">
            <div class="col-md-3"><small>Donor / grantor</small><strong class="d-block">{{ $report['fund']->donor_name ?? 'Not recorded' }}</strong></div>
            <div class="col-md-3"><small>Grant period</small><strong class="d-block">{{ $report['period'] }}</strong></div>
            <div class="col-md-2"><small>Grant amount</small><strong class="d-block font-monospace">{{ $money($report['grant_amount']) }}</strong></div>
            <div class="col-md-2"><small>Utilisation</small><strong class="d-block font-monospace">{{ $report['utilisation'] === null ? '—' : $report['utilisation'].'%' }}</strong></div>
            <div class="col-md-2"><small>Remaining balance</small><strong class="d-block font-monospace">{{ $money($report['remaining']) }}</strong></div>
            @if($report['fund']->restriction_notes)<div class="col-12"><small>Restriction</small><p class="mb-0">{{ $report['fund']->restriction_notes }}</p></div>@endif
        </div>
    </section>
    <section class="surface-card">
        @if(! $report['has_activity'])
            <x-empty-state title="No posted activity" description="No posted journal line is tagged with this grant fund up to this date for the branches shown." />
        @else
            <div class="table-responsive"><table class="table accounting-table">
                <thead><tr><th>Item</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                    <tr><th colspan="2">Income received</th></tr>
                    @forelse($report['income_lines'] as $line)<tr><td style="padding-left: 30px"><code>{{ $line['code'] }}</code> {{ $line['name'] }}</td><td class="text-end font-monospace">{{ $money($line['amount']) }}</td></tr>@empty<tr><td colspan="2" class="table-subtext">No income posted.</td></tr>@endforelse
                    <tr><th>Total income received</th><th class="text-end font-monospace">{{ $money($report['income']) }}</th></tr>
                    <tr><th colspan="2">Expenditure charged</th></tr>
                    @forelse($report['expense_lines'] as $line)<tr><td style="padding-left: 30px"><code>{{ $line['code'] }}</code> {{ $line['name'] }}</td><td class="text-end font-monospace">{{ $money($line['amount']) }}</td></tr>@empty<tr><td colspan="2" class="table-subtext">No expenditure posted.</td></tr>@endforelse
                    <tr><th>Total expenditure charged</th><th class="text-end font-monospace">{{ $money($report['expenditure']) }}</th></tr>
                </tbody>
                <tfoot><tr><th>Funds in hand (income received − expenditure charged)</th><th class="text-end font-monospace">{{ $money($report['in_hand']) }}</th></tr></tfoot>
            </table></div>
        @endif
        <p class="table-subtext mt-3">{{ \App\Services\Funds\GrantUtilisationReport::METHOD }}</p>
    </section>
@endif
@endsection
