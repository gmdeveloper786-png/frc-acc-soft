@extends('layouts.app')
@section('title', 'Grant Utilisation')
@section('eyebrow', 'Funds & Grants')
@section('page-title', 'Grant Utilisation')
@section('page-description', 'Per grant fund: grant amount, period, income received, expenditure charged, utilisation and remaining balance.')
@section('page-actions')
    @include('funds.reports._exports', ['available' => ! $setupRequired, 'exportRoute' => fn ($format) => route('fund-reports.export', ['report' => $reportKey, 'format' => $format] + request()->query())])
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @include('funds.reports._filters', ['showPeriod' => false, 'showAsOf' => true, 'funds' => $funds->filter(fn ($fund) => $fund->isGrant())])
    @php($money = fn ($value) => $value === null ? 'Not recorded' : number_format((float) $value, 2))
    <section class="surface-card">
        <div class="card-heading">
            <div>
                <span class="section-kicker">{{ $report['scope']->label }}</span>
                <h2>As of {{ \Illuminate\Support\Carbon::parse($report['as_of'])->format('d M Y') }}</h2>
            </div>
        </div>
        @if($report['rows'] === [])
            <x-empty-state title="No grant funds" description="No fund with the Grant project category exists. Create one fund per grant (for example GRT-01) with its donor, amount and period." />
        @else
            <div class="table-responsive"><table class="table accounting-table">
                <thead><tr><th>Grant fund</th><th>Donor / grantor</th><th>Grant period</th><th class="text-end">Grant amount</th><th class="text-end">Income received</th><th class="text-end">Expenditure charged</th><th class="text-end">Utilisation</th><th class="text-end">Remaining balance</th><th class="text-end">Funds in hand</th><th></th></tr></thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        <td><code>{{ $row['fund']->code }}</code> {{ $row['fund']->name }}</td>
                        <td>{{ $row['fund']->donor_name ?? '—' }}</td>
                        <td>{{ $row['period'] }}</td>
                        <td class="text-end font-monospace">{{ $money($row['grant_amount']) }}</td>
                        <td class="text-end font-monospace">{{ $money($row['income']) }}</td>
                        <td class="text-end font-monospace">{{ $money($row['expenditure']) }}</td>
                        <td class="text-end font-monospace">{{ $row['utilisation'] === null ? '—' : $row['utilisation'].'%' }}</td>
                        <td class="text-end font-monospace">{{ $money($row['remaining']) }}</td>
                        <td class="text-end font-monospace">{{ $money($row['in_hand']) }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('fund-reports.donor-report', ['fund' => $row['fund']] + request()->only(['branch_id', 'as_of'])) }}">Donor report</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
        <p class="table-subtext mt-3">{{ \App\Services\Funds\GrantUtilisationReport::METHOD }}</p>
    </section>
@endif
@endsection
