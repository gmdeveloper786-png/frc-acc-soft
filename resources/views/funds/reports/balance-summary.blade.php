@extends('layouts.app')
@section('title', 'Fund Balance Summary')
@section('eyebrow', 'Funds & Grants')
@section('page-title', 'Fund Balance Summary')
@section('page-description', 'Income minus expenditure per fund for the period and cumulatively to the end of the period.')
@section('page-actions')
    @include('funds.reports._exports', ['available' => ! $setupRequired && $report['period'] !== null, 'exportRoute' => fn ($format) => route('fund-reports.export', ['report' => $reportKey, 'format' => $format] + request()->query())])
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @include('funds.reports._filters')
    @if($report['period'] === null)
        <section class="surface-card"><x-empty-state title="Choose a period" description="No current fiscal year is set. Select a fiscal year or enter a date range to generate the summary." /></section>
    @else
        @php($money = fn ($value) => number_format((float) $value, 2))
        <section class="surface-card">
            <div class="card-heading">
                <div>
                    <span class="section-kicker">{{ $report['scope']->label }}</span>
                    <h2>{{ $report['period']['label'] }}</h2>
                </div>
            </div>
            @if(! $report['has_activity'])
                <x-empty-state title="No posted income or expenditure" description="No posted journal touches an income or expense account up to the end of this period for the branches and funds shown." />
            @else
                <div class="table-responsive"><table class="table accounting-table">
                    <thead><tr><th>Fund</th><th>Type</th><th class="text-end">Period income</th><th class="text-end">Period expenditure</th><th class="text-end">Period surplus/(deficit)</th><th class="text-end">Cumulative income</th><th class="text-end">Cumulative expenditure</th><th class="text-end">Fund balance</th></tr></thead>
                    <tbody>
                    @foreach($report['rows'] as $row)
                        <tr class="{{ $row['has_activity'] ? '' : 'text-muted' }}">
                            <td>{{ $row['label'] }}</td><td>{{ $row['type'] }}</td>
                            <td class="text-end font-monospace">{{ $money($row['period_income']) }}</td>
                            <td class="text-end font-monospace">{{ $money($row['period_expenditure']) }}</td>
                            <td class="text-end font-monospace">{{ $money($row['period_balance']) }}</td>
                            <td class="text-end font-monospace">{{ $money($row['cumulative_income']) }}</td>
                            <td class="text-end font-monospace">{{ $money($row['cumulative_expenditure']) }}</td>
                            <td class="text-end font-monospace fw-semibold">{{ $money($row['cumulative_balance']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr><th colspan="2">{{ $report['selected'] === null ? 'Total (all lines)' : 'Total of shown funds' }}</th>@foreach(['period_income', 'period_expenditure', 'period_balance', 'cumulative_income', 'cumulative_expenditure', 'cumulative_balance'] as $column)<th class="text-end font-monospace">{{ $money($report['totals'][$column]) }}</th>@endforeach</tr></tfoot>
                </table></div>
            @endif
            <p class="table-subtext mt-3">{{ \App\Services\Funds\FundBalanceSummary::METHOD }}</p>
        </section>
    @endif
@endif
@endsection
