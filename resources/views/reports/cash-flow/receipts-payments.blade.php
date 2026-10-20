@extends('layouts.app')
@section('title', 'Receipts & Payments')
@section('eyebrow', 'Financial statements')
@section('page-title', 'Receipts & Payments Statement')
@section('page-description', 'Cash basis: money that actually moved through cash and bank ledgers. This is separate from the accrual-based Income & Expenditure statement.')
@section('page-actions')
    @can('financial_statements.export')
        @if($ready && ($statement['state'] ?? null) === 'ready')
            @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
                <a class="btn btn-outline-primary" href="{{ route('cash-flow.receipts-payments.export', ['format' => $format, ...request()->query()]) }}">{{ $label }}</a>
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
        $money = fn (string $value): string => \App\Services\AccountingDecimal::format($value, 2, 4);
        $period = \Illuminate\Support\Carbon::parse($from)->format('d M Y').' to '.\Illuminate\Support\Carbon::parse($to)->format('d M Y');
    @endphp

    @unless($statement['reconciled'])
        <div class="alert alert-danger" role="alert"><strong>Not reconciled.</strong> Opening balance plus receipts less payments differs from the General Ledger cash and bank balance by {{ $statement['difference'] }}. The statement is shown as calculated and is not adjusted.</div>
    @endunless

    <div class="kpi-grid">
        <x-kpi-card label="Opening cash and bank" value="{{ $currency }} {{ $money($statement['opening']) }}" icon="O" tone="navy" status="Before {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}" description="Posted balance of cash, bank and petty cash ledgers"/>
        <x-kpi-card label="Total receipts" value="{{ $currency }} {{ $money($statement['total_receipts']) }}" icon="R" tone="teal" status="Cash basis" description="Money received in the period, net of reversed receipts"/>
        <x-kpi-card label="Total payments" value="{{ $currency }} {{ $money($statement['total_payments']) }}" icon="P" tone="amber" status="Cash basis" description="Money paid in the period, net of reversed payments"/>
        <x-kpi-card label="Closing cash and bank" value="{{ $currency }} {{ $money($statement['closing']) }}" icon="C" tone="green" status="{{ $statement['reconciled'] ? 'Reconciled' : 'Not reconciled' }}" description="General Ledger balance at {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}"/>
    </div>

    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">{{ $meta['Branch'] }} · {{ $period }} · Cash basis</span><h2>Receipts &amp; Payments</h2></div><span class="status-badge {{ $statement['reconciled'] ? 'success' : 'danger' }}">{{ $statement['reconciled'] ? 'Reconciled' : 'Not reconciled' }}</span></div>
        <p class="card-copy">Only posted journals that touch a cash, bank or petty cash ledger are included, each counted once. Accrued but unpaid items do not appear. A reversal reduces the side of its original: a reversed payment lowers payments and is not a receipt. Transfers between cash and bank ledgers are excluded.</p>
        <div class="table-responsive"><table class="table accounting-table">
            <thead><tr><th>Counter ledger</th><th>Account type</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
                <tr class="fw-semibold"><td colspan="2">Opening cash and bank balance</td><td class="text-end font-monospace">{{ $money($statement['opening']) }}</td></tr>
                @foreach(['receipts' => 'Receipts', 'payments' => 'Payments'] as $key => $label)
                    <tr class="fw-semibold"><td colspan="3">{{ $label }}</td></tr>
                    @forelse($statement[$key] as $row)
                        <tr><td style="padding-left: 28px"><code>{{ $row['account']->code }}</code> {{ $row['account']->name }}</td><td>{{ ucfirst($row['account']->account_type->value) }}</td><td class="text-end font-monospace">{{ $money($row['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="3" style="padding-left: 28px"><span class="table-subtext">No {{ strtolower($label) }} through cash or bank in this period.</span></td></tr>
                    @endforelse
                    <tr class="fw-semibold"><td colspan="2">Total {{ strtolower($label) }}</td><td class="text-end font-monospace">{{ $money($statement['total_'.$key]) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><th colspan="2">Closing cash and bank balance (opening + receipts − payments)</th><th class="text-end font-monospace">{{ $money($statement['expected_closing']) }}</th></tr>
                <tr><td colspan="2">General Ledger cash and bank balance at period end</td><td class="text-end font-monospace">{{ $money($statement['closing']) }}</td></tr>
                <tr><th colspan="2">Reconciliation</th><th class="text-end">@if($statement['reconciled'])<span class="status-badge success">Reconciled</span>@else<span class="status-badge danger">Difference {{ $statement['difference'] }}</span>@endif</th></tr>
            </tfoot>
        </table></div>
        <p class="card-copy">
            @if($statement['transfers']['count'] > 0)
                {{ $statement['transfers']['count'] }} internal {{ Str::plural('transfer', $statement['transfers']['count']) }} between cash and bank ledgers ({{ $currency }} {{ $money($statement['transfers']['amount']) }}) {{ $statement['transfers']['count'] === 1 ? 'is' : 'are' }} excluded.
            @else
                No internal transfers between cash and bank ledgers in this period.
            @endif
        </p>
    </section>

    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">Balance per account</span><h2>Cash and bank accounts</h2></div></div>
        <div class="table-responsive"><table class="table accounting-table">
            <thead><tr><th>Account</th><th>Type</th><th class="text-end">Opening</th><th class="text-end">Net movement</th><th class="text-end">Closing</th></tr></thead>
            <tbody>
            @forelse($statement['cash_accounts'] as $row)
                <tr><td><code>{{ $row['account']->code }}</code> {{ $row['name'] }}</td><td>{{ ucfirst($row['type']) }}</td><td class="text-end font-monospace">{{ $money($row['opening']) }}</td><td class="text-end font-monospace">{{ $money($row['movement']) }}</td><td class="text-end font-monospace">{{ $money($row['closing']) }}</td></tr>
            @empty
                <tr><td colspan="5"><x-empty-state title="No posted cash or bank activity" description="No posted journal has touched a cash, bank or petty cash ledger up to the end of this period for the selected branch." /></td></tr>
            @endforelse
            </tbody>
            @if($statement['cash_accounts'] !== [])
                <tfoot><tr><th colspan="2">Total</th><th class="text-end font-monospace">{{ $money($statement['opening']) }}</th><th class="text-end font-monospace">{{ $money($statement['cash_movement']) }}</th><th class="text-end font-monospace">{{ $money($statement['closing']) }}</th></tr></tfoot>
            @endif
        </table></div>
        <p class="card-copy">Net movement per account includes transfers between cash and bank accounts, which cancel out in the total.</p>
    </section>
@endif
@endsection
