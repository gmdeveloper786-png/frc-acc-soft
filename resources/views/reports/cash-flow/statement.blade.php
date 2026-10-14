@extends('layouts.app')
@section('title', 'Cash Flow Statement')
@section('eyebrow', 'Financial statements')
@section('page-title', 'Cash Flow Statement')
@section('page-description', 'Direct method: actual cash and bank movement of posted journals, grouped by the activity configured for each ledger.')
@section('page-actions')
    @php $exportable = $ready && ($statement['state'] ?? null) === 'ready' && $statement['reconciled']; @endphp
    @can('financial_statements.export')
        @if($exportable)
            @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
                <a class="btn btn-outline-primary" href="{{ route('cash-flow.statement.export', ['format' => $format, ...request()->query()]) }}">{{ $label }}</a>
            @endforeach
        @endif
    @endcan
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
@endsection
@section('content')
@include('reports.cash-flow._filters')
@include('reports.cash-flow._unavailable')

@if($ready && $statement['state'] === 'ready')
    @php
        $currency = $appSettings['currency_code'];
        $money = fn (string $value): string => number_format((float) $value, 2);
        $period = \Illuminate\Support\Carbon::parse($from)->format('d M Y').' to '.\Illuminate\Support\Carbon::parse($to)->format('d M Y');
    @endphp

    @if($statement['classification_state'] === 'none')
        <div class="alert alert-warning" role="alert">
            <strong>Configuration required: no ledger has a cash-flow classification.</strong>
            Operating, investing and financing activities cannot be reported until ledgers are classified. The amounts below are listed as unclassified; they are not zero activity.
            @can('cash_flow.manage_classification')<a href="{{ route('cash-flow.classification.index') }}">Open the classification screen</a>.@else Ask a user who manages the cash-flow classification to configure it.@endcan
        </div>
    @elseif(! $statement['is_final'])
        <div class="alert alert-warning" role="alert"><strong>{{ \App\Services\Reports\CashFlow\CashFlowStatementService::NOT_FINAL }}.</strong> Some ledgers that moved cash in this period have no configured activity. They are listed under “Unclassified — configuration required”.</div>
    @endif
    @unless($statement['reconciled'])
        <div class="alert alert-danger" role="alert"><strong>Not reconciled.</strong> Opening balance plus net change differs from the General Ledger cash and bank balance by {{ $currency }} {{ $money($statement['difference']) }} ({{ $statement['difference'] }}). The statement is shown as calculated and is not adjusted; export is disabled until the ledger is corrected.</div>
    @endunless

    <div class="kpi-grid">
        <x-kpi-card label="Opening cash and bank" value="{{ $currency }} {{ $money($statement['opening']) }}" icon="O" tone="navy" status="Before {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}" description="Posted balance of all cash, bank and petty cash ledgers"/>
        <x-kpi-card label="Net change in cash" value="{{ $currency }} {{ $money($statement['net_change']) }}" icon="N" tone="teal" status="{{ $statement['journal_count'] }} {{ Str::plural('journal', $statement['journal_count']) }}" description="Inflows less outflows of the period"/>
        <x-kpi-card label="Closing cash and bank" value="{{ $currency }} {{ $money($statement['closing']) }}" icon="C" tone="green" status="General Ledger" description="Posted balance at {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}"/>
        <x-kpi-card label="Reconciliation" value="{{ $statement['reconciled'] ? 'Reconciled' : $currency.' '.$money($statement['difference']) }}" icon="R" tone="{{ $statement['reconciled'] ? 'green' : 'rose' }}" status="{{ $statement['reconciled'] ? 'Agrees with GL' : 'Difference' }}" description="Opening + net change compared with the General Ledger"/>
    </div>

    <section class="surface-card mb-4">
        <div class="card-heading">
            <div><span class="section-kicker">{{ $meta['Branch'] }} · {{ $period }} · {{ $statement['method'] }}</span><h2>Cash Flow Statement</h2></div>
            <span class="status-badge {{ $statement['is_final'] ? ($statement['reconciled'] ? 'success' : 'danger') : 'pending' }}">{{ $statement['is_final'] ? ($statement['reconciled'] ? 'Reconciled' : 'Not reconciled') : \App\Services\Reports\CashFlow\CashFlowStatementService::NOT_FINAL }}</span>
        </div>
        <p class="card-copy">Prepared by the <strong>direct method</strong>. Each posted journal that touches a cash, bank or petty cash ledger is attributed to its counter ledgers in proportion to their amounts. A reversal is counted on its own date against the side of its original, so the two net to zero. Transfers between cash and bank ledgers are excluded.</p>
        <div class="table-responsive"><table class="table accounting-table">
            <thead><tr><th>Activity / counter ledger</th><th class="text-end">Inflows</th><th class="text-end">Outflows</th><th class="text-end">Net</th></tr></thead>
            <tbody>
            @if($statement['classification_state'] !== 'none')
                @foreach($statement['sections'] as $section)
                    <tr class="fw-semibold"><td colspan="4">{{ $section['activity']->label() }}</td></tr>
                    @forelse($section['rows'] as $row)
                        <tr><td style="padding-left: 28px"><code>{{ $row['account']->code }}</code> {{ $row['account']->name }}<span class="table-subtext">{{ ucfirst($row['account']->account_type->value) }}</span></td><td class="text-end font-monospace">{{ $money($row['inflows']) }}</td><td class="text-end font-monospace">{{ $money($row['outflows']) }}</td><td class="text-end font-monospace">{{ $money($row['net']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" style="padding-left: 28px"><span class="table-subtext">No cash movement on ledgers classified as {{ strtolower($section['activity']->label()) }} in this period.</span></td></tr>
                    @endforelse
                    <tr class="fw-semibold"><td>Net cash from {{ strtolower($section['activity']->label()) }}</td><td class="text-end font-monospace">{{ $money($section['inflows']) }}</td><td class="text-end font-monospace">{{ $money($section['outflows']) }}</td><td class="text-end font-monospace">{{ $money($section['net']) }}</td></tr>
                @endforeach
            @endif
            @if($statement['unclassified']['rows'] !== [])
                <tr class="fw-semibold"><td colspan="4">Unclassified — configuration required @can('cash_flow.manage_classification')<a class="ms-2 fw-normal" href="{{ route('cash-flow.classification.index', ['activity' => 'unclassified']) }}">Classify these ledgers</a>@endcan</td></tr>
                @foreach($statement['unclassified']['rows'] as $row)
                    <tr><td style="padding-left: 28px"><code>{{ $row['account']->code }}</code> {{ $row['account']->name }}<span class="table-subtext">{{ ucfirst($row['account']->account_type->value) }} · no cash-flow activity configured</span></td><td class="text-end font-monospace">{{ $money($row['inflows']) }}</td><td class="text-end font-monospace">{{ $money($row['outflows']) }}</td><td class="text-end font-monospace">{{ $money($row['net']) }}</td></tr>
                @endforeach
                <tr class="fw-semibold"><td>Net unclassified cash movement</td><td class="text-end font-monospace">{{ $money($statement['unclassified']['inflows']) }}</td><td class="text-end font-monospace">{{ $money($statement['unclassified']['outflows']) }}</td><td class="text-end font-monospace">{{ $money($statement['unclassified']['net']) }}</td></tr>
            @endif
            </tbody>
            <tfoot>
                <tr><th colspan="3">Net change in cash and bank</th><th class="text-end font-monospace">{{ $money($statement['net_change']) }}</th></tr>
                <tr><td colspan="3">Opening cash and bank balance</td><td class="text-end font-monospace">{{ $money($statement['opening']) }}</td></tr>
                <tr><th colspan="3">Closing cash and bank balance (opening + net change)</th><th class="text-end font-monospace">{{ $money($statement['expected_closing']) }}</th></tr>
                <tr><td colspan="3">General Ledger cash and bank balance at period end</td><td class="text-end font-monospace">{{ $money($statement['closing']) }}</td></tr>
                <tr><th colspan="3">Reconciliation</th><th class="text-end">@if($statement['reconciled'])<span class="status-badge success">Reconciled</span>@else<span class="status-badge danger">Difference {{ $statement['difference'] }}</span>@endif</th></tr>
            </tfoot>
        </table></div>
        <p class="card-copy">
            @if($statement['transfers']['count'] > 0)
                {{ $statement['transfers']['count'] }} internal {{ Str::plural('transfer', $statement['transfers']['count']) }} between cash and bank ledgers ({{ $currency }} {{ $money($statement['transfers']['amount']) }}) {{ $statement['transfers']['count'] === 1 ? 'is' : 'are' }} excluded from inflows and outflows.
            @else
                No internal transfers between cash and bank ledgers in this period.
            @endif
        </p>
    </section>
@endif
@endsection
