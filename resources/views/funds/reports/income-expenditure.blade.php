@extends('layouts.app')
@section('title', 'Fund-wise Income & Expenditure')
@section('eyebrow', 'Funds & Grants')
@section('page-title', 'Fund-wise Income & Expenditure')
@section('page-description', 'Accrual income and expenditure with one column per fund, a "No fund" column and the total.')
@section('page-actions')
    @include('funds.reports._exports', ['available' => ! $setupRequired && $report['period'] !== null, 'exportRoute' => fn ($format) => route('fund-reports.export', ['report' => $reportKey, 'format' => $format] + request()->query())])
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @include('funds.reports._filters')
    @if($report['period'] === null)
        <section class="surface-card"><x-empty-state title="Choose a period" description="No current fiscal year is set. Select a fiscal year or enter a date range to generate the statement." /></section>
    @else
        @php($columns = $report['columns'])
        <section class="surface-card">
            <div class="card-heading">
                <div>
                    <span class="section-kicker">{{ $report['scope']->label }}</span>
                    <h2>{{ $report['period']['label'] }}</h2>
                </div>
            </div>
            @if($report['selected'] !== null)
                <div class="alert alert-info">Only the selected fund{{ $report['funds']->count() > 1 ? ' and its sub-funds are' : ' is' }} shown; the total is the total of the shown funds.</div>
            @endif
            @if(! $report['has_activity'])
                <x-empty-state title="No posted income or expenditure" description="No posted journal touches an income or expense account in this period for the branches and funds shown." />
            @else
                <div class="table-responsive"><table class="table accounting-table">
                    <thead><tr><th>Account</th>@foreach($columns as $column)<th class="text-end">{{ $report['labels'][$column] }}</th>@endforeach</tr></thead>
                    <tbody>
                        <tr><th colspan="{{ count($columns) + 1 }}">Income</th></tr>
                        @include('reports.statements._tree', ['tree' => $report['income']])
                        <tr><th>Total Income</th>@foreach($columns as $column)<th class="text-end font-monospace">{{ number_format((float) $report['total_income'][$column], 2) }}</th>@endforeach</tr>
                        <tr><th colspan="{{ count($columns) + 1 }}">Expenses</th></tr>
                        @include('reports.statements._tree', ['tree' => $report['expenses']])
                        <tr><th>Total Expenses</th>@foreach($columns as $column)<th class="text-end font-monospace">{{ number_format((float) $report['total_expenses'][$column], 2) }}</th>@endforeach</tr>
                    </tbody>
                    <tfoot><tr><th>Surplus/(Deficit)</th>@foreach($columns as $column)<th class="text-end font-monospace">{{ number_format((float) $report['surplus'][$column], 2) }}</th>@endforeach</tr></tfoot>
                </table></div>
            @endif
            <p class="table-subtext mt-3">{{ \App\Services\Funds\FundIncomeExpenditureReport::METHOD }}</p>
        </section>
    @endif
@endif
@endsection
