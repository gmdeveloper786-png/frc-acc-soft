@extends('layouts.app')
@section('title', 'FRC Payment '.$payment->frc_payment_id)
@section('eyebrow', 'FRC fee integration')
@section('page-title', 'FRC Payment '.($payment->receipt_number ?? '#'.$payment->frc_payment_id))
@section('page-description', 'Source fields as mirrored from the FRC Management Software. The mirror holds no personal data.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('frc-integration.payments.index') }}">Back to source payments</a>@endsection
@section('content')
@php
    $money = fn ($value) => \App\Services\Frc\FinancialSync\FrcPaymentListService::money($value);
    $currency = $appSettings['currency_code'];
    $fmt = fn ($date) => $date ? $date->format('d M Y H:i') : '—';
    $enrollment = $payment->enrollment;
@endphp

@if($payment->posting_status === 'needs_review')
    <div class="alert alert-danger" role="alert"><strong>Needs review.</strong> {{ $payment->posting_note }}</div>
@endif

<div class="row g-4">
    <div class="col-lg-6"><section class="surface-card h-100">
        <div class="card-heading"><div><span class="section-kicker">Source</span><h2>Payment</h2></div></div>
        <table class="table accounting-table"><tbody>
            <tr><th>FRC payment id</th><td class="font-monospace">{{ $payment->frc_payment_id }}</td></tr>
            <tr><th>Receipt number</th><td>{{ $payment->receipt_number ?? '— (assigned only when paid)' }}</td></tr>
            <tr><th>Amount</th><td class="font-monospace">{{ $currency }} {{ $money($payment->amount) }}</td></tr>
            <tr><th>Method</th><td>{{ str($payment->payment_method)->headline() }}</td></tr>
            <tr><th>Source status</th><td>{{ str($payment->source_status)->headline() }}</td></tr>
            <tr><th>Payment date</th><td>{{ $payment->payment_date->format('d M Y') }}</td></tr>
            <tr><th>Verified at</th><td>{{ $fmt($payment->verified_at) }}</td></tr>
            <tr><th>Created / updated in FRC</th><td>{{ $fmt($payment->source_created_at) }} / {{ $fmt($payment->source_updated_at) }}</td></tr>
            <tr><th>Deleted in FRC</th><td>{{ $payment->source_deleted_at ? $fmt($payment->source_deleted_at) : 'No' }}</td></tr>
            <tr><th>Last synced</th><td>{{ $fmt($payment->updated_at) }} (run #{{ $payment->last_sync_run_id ?? '—' }})</td></tr>
        </tbody></table>
    </section></div>
    <div class="col-lg-6"><section class="surface-card h-100">
        <div class="card-heading"><div><span class="section-kicker">Enrollment and mapping</span><h2>Enrollment</h2></div></div>
        <table class="table accounting-table"><tbody>
            <tr><th>FRC enrollment id</th><td class="font-monospace">{{ $payment->frc_enrollment_id }}</td></tr>
            <tr><th>Enrollment status</th><td>{{ str($enrollment?->source_status ?? $payment->enrollment_status ?? 'unknown')->headline() }}@unless($enrollment)<span class="table-subtext">Enrollment not mirrored yet; value taken from the payment.</span>@endunless</td></tr>
            @if($enrollment)
                <tr><th>Final total (after discount)</th><td class="font-monospace">{{ $currency }} {{ $money($enrollment->final_total) }}</td></tr>
                <tr><th>Paid / remaining (FRC)</th><td class="font-monospace">{{ $money($enrollment->paid_amount) }} / {{ $money($enrollment->remaining_amount) }}</td></tr>
            @endif
            <tr><th>Branch</th><td>{!! $payment->branch ? e($payment->branch->name).' <span class="table-subtext">FRC '.e($payment->frc_branch_id).'</span>' : '<span class="status-badge danger">Unmapped</span> <span class="table-subtext">FRC branch '.e($payment->frc_branch_id ?? 'not sent').'</span>' !!}</td></tr>
            <tr><th>Service</th><td>{!! $payment->service ? e($payment->service->name).' <span class="table-subtext">FRC '.e($payment->frc_service_id).'</span>' : '<span class="status-badge danger">Unmapped</span> <span class="table-subtext">FRC service '.e($payment->frc_service_id ?? 'not sent').'</span>' !!}</td></tr>
        </tbody></table>
    </section></div>
</div>

<section class="surface-card mt-4">
    <div class="card-heading"><div><span class="section-kicker">Accounting</span><h2>Posting status: {{ str($payment->posting_status)->headline() }}</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table align-middle">
        <thead><tr><th>Posting</th><th>Basis</th><th>Status</th><th class="text-end">Amount</th><th>Journal</th></tr></thead>
        <tbody>
        @forelse($payment->postings as $posting)
            <tr>
                <td class="font-monospace">{{ $posting->source_key }}</td>
                <td>{{ ucfirst($posting->basis) }}</td>
                <td>{{ ucfirst($posting->status) }}</td>
                <td class="text-end font-monospace">{{ $money($posting->amount) }}</td>
                <td>
                    @if($posting->journalEntry)
                        @can('journals.view')<a href="{{ route('journals.show', $posting->journalEntry) }}">{{ $posting->journalEntry->voucher_number ?? 'Journal #'.$posting->journalEntry->id }}</a>@else{{ $posting->journalEntry->voucher_number ?? 'Journal #'.$posting->journalEntry->id }}@endcan
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><span class="table-subtext">Not posted. Only paid payments of approved, active or completed enrollments with mapped branch, service and settlement account can be posted, through the separate fee posting step.</span></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="surface-card mt-4">
    <div class="card-heading"><div><span class="section-kicker">Payment and its enrollment</span><h2>Sync exceptions</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table align-middle">
        <thead><tr><th>Raised</th><th>Code</th><th>Message</th><th>State</th></tr></thead>
        <tbody>
        @forelse($exceptions as $exception)
            <tr><td>{{ $fmt($exception->created_at) }}</td><td><code>{{ $exception->code }}</code></td><td>{{ $exception->message }}</td><td><span class="status-badge {{ $exception->resolved_at ? 'success' : 'danger' }}">{{ $exception->resolved_at ? 'Resolved' : 'Open' }}</span></td></tr>
        @empty
            <tr><td colspan="4"><span class="table-subtext">No exceptions for this payment.</span></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
