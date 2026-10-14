@extends('layouts.app')
@section('title', 'Branch-wise Income & Expenditure')
@section('eyebrow', 'Financial Statements')
@section('page-title', 'Branch-wise Income & Expenditure')
@section('page-description', 'Accrual income and expenditure with one column per branch you may see, and their total.')
@section('page-actions')
    @include('reports.statements._exports', ['available' => ! $setupRequired && $report['period'] !== null])
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @include('reports.statements._filters', ['showBranch' => false])
    @if($report['period'] === null)
        <section class="surface-card"><x-empty-state title="Choose a period" description="No current fiscal year is set. Select a fiscal year or enter a date range to generate the statement." /></section>
    @else
        @php
            $columns = $report['columns'];
            $labels = $report['branch_columns'] + ['total' => $report['total_label']];
        @endphp
        <section class="surface-card">
            <div class="card-heading">
                <div>
                    <span class="section-kicker">{{ $report['scope']->label }}</span>
                    <h2>{{ $report['period']['label'] }}</h2>
                </div>
            </div>
            @unless($report['scope']->consolidated)
                <div class="alert alert-warning">Only the branches you are authorized for are shown. The total is the total of the shown branches, not of all FRC.</div>
            @endunless
            @if(! $report['has_activity'])
                <x-empty-state title="No posted income or expenditure" description="No posted journal touches an income or expense account in this period for the branches shown." />
            @else
                <div class="table-responsive"><table class="table accounting-table">
                    <thead><tr><th>Account</th>@foreach($columns as $column)<th class="text-end">{{ $labels[$column] }}</th>@endforeach</tr></thead>
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
            <p class="table-subtext mt-3">{{ \App\Services\Reports\Statements\IncomeExpenditureStatement::METHOD }}</p>
        </section>
    @endif
@endif
@endsection
