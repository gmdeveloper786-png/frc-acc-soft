@extends('layouts.app')
@section('title', $unmapped ? 'Unmapped FRC Payments' : 'FRC Source Payments')
@section('eyebrow', 'FRC fee integration')
@section('page-title', $unmapped ? 'Unmapped Payments' : 'Source Payments')
@section('page-description', $unmapped
    ? 'Mirrored payments whose FRC branch or service does not exist locally. Run the branch or service sync; they cannot be posted until mapped.'
    : 'FRC fee payments as mirrored from the source, every status. No personal data is stored in the mirror.')
@section('content')
@php
    $money = fn ($value) => \App\Services\Frc\FinancialSync\FrcPaymentListService::money($value);
    $currency = $appSettings['currency_code'];
    $tone = ['paid' => 'success', 'pending_verification' => 'pending', 'rejected' => 'danger', 'cancelled' => 'danger', 'refunded' => 'danger'];
    $postingTone = ['posted' => 'success', 'needs_review' => 'danger', 'reversed' => 'neutral', 'unposted' => 'pending'];
@endphp
@include('frc-integration._filters', ['paymentFilters' => true])

<div class="kpi-grid">
    <x-kpi-card label="Records" value="{{ number_format($summary['count']) }}" icon="#" tone="navy" status="Filtered" description="Payment rows matching the filters, all statuses"/>
    <x-kpi-card label="Paid total" value="{{ $currency }} {{ $money($summary['paid']) }}" icon="P" tone="teal" status="Status paid" description="Approved, active or completed enrollments (Finance Report rule){{ (float) $summary['paid_excluded'] > 0 ? '; '.$currency.' '.$money($summary['paid_excluded']).' paid on other enrollments excluded' : '' }}"/>
    <x-kpi-card label="Cash" value="{{ $currency }} {{ $money($summary['cash']) }}" icon="C" tone="green" status="Paid in cash" description="Method cash"/>
    <x-kpi-card label="Online / bank" value="{{ $currency }} {{ $money($summary['online']) }}" icon="B" tone="navy" status="Paid other methods" description="Bank transfer, Easypaisa, JazzCash, card, other"/>
    <x-kpi-card label="Pending verification" value="{{ $currency }} {{ $money($summary['pending_verification']) }}" icon="V" tone="amber" status="{{ $summary['pending_count'] }} payments" description="Uploaded slips not yet verified; never posted"/>
</div>

<section class="surface-card">
    <div class="table-responsive"><table class="table accounting-table align-middle">
        <thead><tr><th>Payment date</th><th>Receipt</th><th>FRC id</th><th>Branch</th><th>Service</th><th>Method</th><th>Source status</th><th>Posting</th><th class="text-end">Amount</th><th></th></tr></thead>
        <tbody>
        @forelse($payments as $payment)
            <tr>
                <td>{{ $payment->payment_date->format('d M Y') }}</td>
                <td>{{ $payment->receipt_number ?? '—' }}</td>
                <td class="font-monospace">{{ $payment->frc_payment_id }}</td>
                <td>{!! $payment->branch ? e($payment->branch->name) : '<span class="status-badge danger">Unmapped (FRC '.e($payment->frc_branch_id ?? '—').')</span>' !!}</td>
                <td>{!! $payment->service ? e($payment->service->name) : '<span class="status-badge danger">Unmapped (FRC '.e($payment->frc_service_id ?? '—').')</span>' !!}</td>
                <td>{{ str($payment->payment_method)->headline() }}</td>
                <td><span class="status-badge {{ $tone[$payment->source_status] ?? 'neutral' }}">{{ str($payment->source_status)->headline() }}</span>@if($payment->source_deleted_at)<span class="table-subtext">Deleted in FRC</span>@endif</td>
                <td><span class="status-badge {{ $postingTone[$payment->posting_status] ?? 'neutral' }}">{{ str($payment->posting_status)->headline() }}</span></td>
                <td class="text-end font-monospace">{{ $money($payment->amount) }}</td>
                <td><a class="btn btn-sm btn-outline-primary" href="{{ route('frc-integration.payments.show', $payment) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="10">
                @if(! $hasAnyPayment)
                    <x-empty-state title="No FRC payments mirrored yet" description="Nothing has been synced. The FRC payments endpoint awaits approval; once available, run a sync from the FRC Financial Integration screen." />
                @elseif($unmapped)
                    <x-empty-state title="No unmapped payments" description="Every mirrored payment you can see resolves to a local branch and service." />
                @else
                    <x-empty-state title="No payments match these filters" description="Change or clear the filters to see more mirrored payments." />
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $payments->links() }}
</section>
@endsection
