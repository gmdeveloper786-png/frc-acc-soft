@extends('layouts.app')
@section('title', 'Service Analysis')
@section('eyebrow', 'Financial Statements')
@section('page-title', 'Service Analysis')
@section('page-description', 'Service-wise fee income, recorded expenses and direct result, from the service recorded on posted journal lines.')
@section('page-actions')
    @include('reports.statements._exports', ['available' => ! $setupRequired && $report['period'] !== null])
@endsection
@section('content')
@if($setupRequired)
    @include('reports.statements._setup')
@else
    @include('reports.statements._filters')
    @if($report['period'] === null)
        <section class="surface-card"><x-empty-state title="Choose a period" description="No current fiscal year is set. Select a fiscal year or enter a date range to generate the analysis." /></section>
    @else
        @php
            $statement = \App\Services\Reports\Statements\ServiceAnalysisStatement::class;
            $money = fn (?string $value): string => $value === null ? '—' : number_format((float) $value, 2);
            $sections = ['income' => ['Service-wise fee income', 'Total Income'], 'expenses' => ['Service-wise recorded expenses', 'Total Expenses']];
        @endphp
        <section class="surface-card mb-4">
            <div class="card-heading">
                <div>
                    <span class="section-kicker">{{ $report['scope']->label }}</span>
                    <h2>{{ $report['period']['label'] }}</h2>
                </div>
            </div>
            <div class="alert alert-warning mb-0">The direct result shown here is the <strong>direct result before unallocated shared costs</strong>, not profitability. Income and expenses recorded without a service stay in the "{{ $statement::UNALLOCATED }}" row and are never distributed across services.</div>
        </section>
        @if(! $report['has_activity'])
            <section class="surface-card"><x-empty-state title="No posted income or expenditure" description="No posted journal touches an income or expense account in this period and branch scope." /></section>
        @else
            <section class="surface-card mb-4">
                <div class="card-heading"><div><span class="section-kicker">Summary</span><h2>Service Performance Summary</h2></div></div>
                <div class="table-responsive"><table class="table accounting-table">
                    <thead><tr><th>Service</th><th class="text-end">Direct income</th><th class="text-end">Direct expenses</th><th class="text-end">Direct result before unallocated shared costs</th></tr></thead>
                    <tbody>
                        @forelse($report['summary'] as $line)
                            <tr><td>{{ $line['name'] }}@if($line['result'] === null)<span class="table-subtext">No posted activity</span>@endif</td><td class="text-end font-monospace">{{ $money($line['income']) }}</td><td class="text-end font-monospace">{{ $money($line['expenses']) }}</td><td class="text-end font-monospace">{{ $money($line['result']) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="table-subtext">No services have been synchronised from FRC yet.</td></tr>
                        @endforelse
                        <tr><th>Total attributed to services</th><th class="text-end font-monospace">{{ $money($report['income']['allocated_total']) }}</th><th class="text-end font-monospace">{{ $money($report['expenses']['allocated_total']) }}</th><th class="text-end font-monospace">{{ $money($report['direct_result_total']) }}</th></tr>
                        <tr><td>{{ $statement::UNALLOCATED }}<span class="table-subtext">Not distributed across services</span></td><td class="text-end font-monospace">{{ $money($report['income']['unallocated']['amount']) }}</td><td class="text-end font-monospace">{{ $money($report['expenses']['unallocated']['amount']) }}</td><td class="text-end">—</td></tr>
                    </tbody>
                    <tfoot><tr><th>Overall surplus/(deficit) for the period</th><th class="text-end font-monospace">{{ $money($report['income']['total']) }}</th><th class="text-end font-monospace">{{ $money($report['expenses']['total']) }}</th><th class="text-end font-monospace">{{ $money($report['overall_result']) }}</th></tr></tfoot>
                </table></div>
            </section>
            @foreach($sections as $key => [$title, $totalLabel])
                <section class="surface-card mb-4">
                    <div class="card-heading"><div><span class="section-kicker">{{ $key === 'income' ? 'Income' : 'Expenses' }}</span><h2>{{ $title }}</h2></div></div>
                    <div class="table-responsive"><table class="table accounting-table">
                        <thead><tr><th>Service / ledger</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            @foreach([...$report[$key]['services'], $report[$key]['unallocated']] as $group)
                                <tr><td class="fw-semibold">{{ $group['name'] }}@if($group['amount'] === null)<span class="table-subtext">No posted activity</span>@endif</td><td class="text-end font-monospace fw-semibold">{{ $money($group['amount']) }}</td></tr>
                                @foreach($group['ledgers'] as $ledger)
                                    <tr><td style="padding-left: 30px"><code>{{ $ledger['code'] }}</code> {{ $ledger['name'] }}</td><td class="text-end font-monospace">{{ $money($ledger['amount']) }}</td></tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot><tr><th>{{ $totalLabel }}</th><th class="text-end font-monospace">{{ $money($report[$key]['total']) }}</th></tr></tfoot>
                    </table></div>
                </section>
            @endforeach
        @endif
        <p class="table-subtext">{{ $statement::METHOD }}</p>
    @endif
@endif
@endsection
