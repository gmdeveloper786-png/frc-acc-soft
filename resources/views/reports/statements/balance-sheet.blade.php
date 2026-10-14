@extends('layouts.app')
@section('title', 'Balance Sheet')
@section('eyebrow', 'Financial Statements')
@section('page-title', 'Balance Sheet / Statement of Financial Position')
@section('page-description', 'Assets, liabilities and fund/equity from all posted journals up to the as-of date, with the accounting equation checked.')
@section('page-actions')
    @include('reports.statements._exports', ['available' => ! $setupRequired])
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @php
        $statement = \App\Services\Reports\Statements\BalanceSheetStatement::class;
        $columns = ['balance'];
        $money = fn (string $value): string => number_format((float) $value, 2);
    @endphp
    @include('reports.statements._filters', ['showPeriod' => false, 'showAsOf' => true])
    <section class="surface-card">
        <div class="card-heading">
            <div>
                <span class="section-kicker">{{ $report['scope']->label }}</span>
                <h2>As of {{ $report['as_of_label'] }}</h2>
                <span class="table-subtext">{{ $report['scope']->consolidated ? 'Consolidated view' : 'Branch view' }}</span>
            </div>
            <div>
                @if($report['limitation'])<span class="status-badge pending">{{ $report['limitation'] }}</span>@endif
                @if($report['balanced'])
                    <span class="status-badge success">Balanced</span>
                @else
                    <span class="status-badge danger">OUT OF BALANCE by {{ $money($report['difference']) }}</span>
                @endif
            </div>
        </div>
        @if(! $report['has_opening_balance'])<div class="alert alert-warning">{{ $statement::OPENING_NOTICE }}</div>@endif
        @if($report['limitation'])
            <div class="alert alert-warning">{{ $report['limitation'] }}. This branch's own posted entries do not balance (for example because inter-branch or organisation-level entries exist), so this is not presented as a complete statement.</div>
        @endif
        @if(! $report['balanced'])
            <div class="alert alert-danger">OUT OF BALANCE by {{ $money($report['difference']) }}: Assets minus Liabilities, Fund/Equity and the unclosed surplus. The difference is reported as found and is not adjusted into any line.</div>
        @endif
        @if(! $report['has_activity'])
            <x-empty-state title="No posted balances" description="No journal has been posted in this branch scope up to the as-of date." />
        @else
            <div class="table-responsive"><table class="table accounting-table">
                <thead><tr><th>Account</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                    <tr><th colspan="2">Assets</th></tr>
                    @include('reports.statements._tree', ['tree' => $report['assets']])
                    <tr><th>Total Assets</th><th class="text-end font-monospace">{{ $money($report['total_assets']) }}</th></tr>
                    <tr><th colspan="2">Liabilities</th></tr>
                    @include('reports.statements._tree', ['tree' => $report['liabilities']])
                    <tr><th>Total Liabilities</th><th class="text-end font-monospace">{{ $money($report['total_liabilities']) }}</th></tr>
                    <tr><th colspan="2">Fund/Equity</th></tr>
                    @include('reports.statements._tree', ['tree' => $report['equity']])
                    <tr>
                        <td style="padding-left: 12px">{{ $statement::UNCLOSED_LABEL }} <span class="status-badge neutral">Computed</span><span class="table-subtext">Income {{ $money($report['total_income']) }} minus expenses {{ $money($report['total_expenses']) }} posted up to the as-of date.</span></td>
                        <td class="text-end font-monospace">{{ $money($report['unclosed_surplus']) }}</td>
                    </tr>
                    <tr><th>Total Fund/Equity</th><th class="text-end font-monospace">{{ $money($report['total_equity_with_surplus']) }}</th></tr>
                </tbody>
                <tfoot>
                    <tr><th>Total Liabilities and Fund/Equity</th><th class="text-end font-monospace">{{ $money($report['total_liabilities_and_equity']) }}</th></tr>
                    <tr><th>Difference (Assets minus Liabilities and Fund/Equity)</th><th class="text-end font-monospace">{{ $money($report['difference']) }}</th></tr>
                </tfoot>
            </table></div>
        @endif
        <p class="table-subtext mt-3">{{ $statement::METHOD }}</p>
    </section>
@endif
@endsection
