@extends('layouts.app')
@section('title', 'FRC Fee Reconciliation')
@section('eyebrow', 'FRC fee integration')
@section('page-title', 'FRC Fee Reconciliation')
@section('page-description', 'Mirrored FRC payments, using the Finance Report rules, compared with the fee postings in the ledger.')
@section('page-actions')
    @can('financial_statements.export')
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
            <a class="btn btn-outline-primary" href="{{ route('frc-integration.reconciliation.export', ['format' => $format, ...request()->only(['from', 'to', 'branch_id', 'service_id'])]) }}">{{ $label }}</a>
        @endforeach
    @endcan
@endsection
@section('content')
@php
    $money = fn ($value) => \App\Services\Frc\FinancialSync\FrcPaymentListService::money($value);
    $currency = $appSettings['currency_code'];
    $source = $result['source'];
    $ledger = $result['ledger'];
@endphp
@include('frc-integration._filters', ['paymentFilters' => false])

@if($result['status'] === 'empty')
    <x-empty-state title="Nothing to reconcile" description="No mirrored FRC payments fall in this scope. If nothing has been synced yet, the FRC payments and enrollments endpoints may still be awaiting approval." />
@else
    <div class="alert {{ $result['status'] === 'reconciled' ? 'alert-success' : 'alert-danger' }}" role="status">
        <strong>{{ $result['status'] === 'reconciled' ? 'Reconciled.' : 'Not reconciled.' }}</strong>
        {{ $result['status'] === 'reconciled'
            ? 'Every eligible paid payment is posted exactly once with the same amount, and there are no open exceptions.'
            : $result['difference_total'].' payment(s) with differences and '.$result['open_exceptions']['count'].' open exception(s).' }}
    </div>

    <div class="kpi-grid">
        <x-kpi-card label="Total paid" value="{{ $currency }} {{ $money($source['paid_total']) }}" icon="P" tone="teal" status="{{ $source['paid_count'] }} payments" description="Paid, enrollment approved/active/completed"/>
        <x-kpi-card label="Cash received" value="{{ $currency }} {{ $money($source['cash_total']) }}" icon="C" tone="green" status="Method cash" description="Finance Report rule"/>
        <x-kpi-card label="Online / bank received" value="{{ $currency }} {{ $money($source['online_total']) }}" icon="B" tone="navy" status="Other methods" description="Bank, Easypaisa, JazzCash, card, other"/>
        <x-kpi-card label="Pending verification" value="{{ $currency }} {{ $money($source['pending_total']) }}" icon="V" tone="amber" status="{{ $source['pending_count'] }} payments" description="Not eligible for posting"/>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6"><section class="surface-card h-100">
            <div class="card-heading"><div><span class="section-kicker">FRC mirror · Finance Report rules</span><h2>Source</h2></div></div>
            <table class="table accounting-table"><tbody>
                <tr><th>Payment records (approved/active/completed enrollments, all statuses)</th><td class="text-end font-monospace">{{ $source['record_count'] }}</td></tr>
                <tr><th>Total paid</th><td class="text-end font-monospace">{{ $money($source['paid_total']) }}</td></tr>
                <tr><th>Cash received</th><td class="text-end font-monospace">{{ $money($source['cash_total']) }}</td></tr>
                <tr><th>Online/bank received</th><td class="text-end font-monospace">{{ $money($source['online_total']) }}</td></tr>
                <tr><th>Pending verification ({{ $source['pending_count'] }})</th><td class="text-end font-monospace">{{ $money($source['pending_total']) }}</td></tr>
                <tr><th>Rejected ({{ $source['rejected_count'] }})</th><td class="text-end font-monospace">{{ $money($source['rejected_total']) }}</td></tr>
                <tr><th>Cancelled ({{ $source['cancelled_count'] }})</th><td class="text-end font-monospace">{{ $money($source['cancelled_total']) }}</td></tr>
                <tr><th>Refunded ({{ $source['refunded_count'] }})</th><td class="text-end font-monospace">{{ $money($source['refunded_total']) }}</td></tr>
                <tr><th>Paid but enrollment not approved/active/completed ({{ $source['excluded_paid_count'] }}) — excluded</th><td class="text-end font-monospace">{{ $money($source['excluded_paid_total']) }}</td></tr>
                <tr><th>Deleted in FRC (excluded)</th><td class="text-end font-monospace">{{ $source['deleted_count'] }}</td></tr>
            </tbody></table>
        </section></div>
        <div class="col-lg-6"><section class="surface-card h-100">
            <div class="card-heading"><div><span class="section-kicker">Fee postings</span><h2>Accounting</h2></div></div>
            <table class="table accounting-table"><tbody>
                <tr><th>Eligible amount ({{ $ledger['eligible_count'] }})</th><td class="text-end font-monospace">{{ $money($ledger['eligible_total']) }}</td></tr>
                <tr><th>Posted amount</th><td class="text-end font-monospace">{{ $money($ledger['posted_total']) }}</td></tr>
                <tr><th>Unposted eligible amount ({{ $ledger['unposted_eligible_count'] }})</th><td class="text-end font-monospace">{{ $money($ledger['unposted_eligible_total']) }}</td></tr>
                <tr><th>Reversed amount</th><td class="text-end font-monospace">{{ $money($ledger['reversed_total']) }}</td></tr>
                <tr><th>Needs review</th><td class="text-end font-monospace">{{ $ledger['needs_review_count'] }}</td></tr>
                <tr><th>Unmapped payments</th><td class="text-end font-monospace">{{ $ledger['unmapped_count'] }}</td></tr>
                <tr><th>Open exceptions</th><td class="text-end font-monospace">{{ $result['open_exceptions']['count'] }}@if($result['open_exceptions']['unlinked'])<span class="table-subtext">{{ $result['open_exceptions']['unlinked'] }} for records that are not mirrored</span>@endif</td></tr>
            </tbody></table>
        </section></div>
    </div>

    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">By type</span><h2>Differences</h2></div></div>
        <table class="table accounting-table"><tbody>
            @foreach($labels as $type => $label)
                <tr><th>{{ $label }}</th><td class="text-end font-monospace">{{ $result['difference_counts'][$type] }}</td></tr>
            @endforeach
        </tbody></table>
    </section>

    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">Receipt level</span><h2>Payments with differences</h2></div></div>
        <div class="table-responsive"><table class="table accounting-table align-middle">
            <thead><tr><th>Payment date</th><th>Receipt</th><th>FRC id</th><th>Status</th><th>Enrollment</th><th class="text-end">Source amount</th><th class="text-end">Posted</th><th>Differences</th></tr></thead>
            <tbody>
            @forelse($result['rows'] as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row['payment_date'])->format('d M Y') }}</td>
                    <td><a href="{{ route('frc-integration.payments.show', $row['id']) }}">{{ $row['receipt_number'] ?? '—' }}</a></td>
                    <td class="font-monospace">{{ $row['frc_payment_id'] }}</td>
                    <td>{{ str($row['source_status'])->headline() }} · {{ str($row['payment_method'])->headline() }}</td>
                    <td>{{ str($row['enrollment_status'] ?? 'unknown')->headline() }}</td>
                    <td class="text-end font-monospace">{{ $money($row['amount']) }}</td>
                    <td class="text-end font-monospace">{{ $money($row['posted_amount']) }}@if($row['posted_count'] > 1) ({{ $row['posted_count'] }}×)@endif</td>
                    <td>@foreach($row['types'] as $type)<span class="status-badge danger">{{ $labels[$type] }}</span> @endforeach</td>
                </tr>
            @empty
                <tr><td colspan="8"><span class="table-subtext">No receipt-level differences in this scope.</span></td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if($result['rows_truncated'])<p class="card-copy">Showing the first {{ count($result['rows']) }} of {{ $result['difference_total'] }} rows. The export contains every row.</p>@endif
    </section>
@endif

<p class="card-copy"><strong>Note:</strong> {{ $note }}</p>
@endsection
