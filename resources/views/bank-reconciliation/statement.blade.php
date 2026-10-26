@extends('layouts.app')
@php
    $account = $reconciliation->financialAccount;
    $amount = fn ($value) => \App\Services\BankReconciliation\Amount::format($value);
    $bookAmount = fn ($line) => \App\Services\BankReconciliation\Amount::journalLine($line->debit, $line->credit)->abs();
@endphp
@section('title','Bank Reconciliation Statement') @section('eyebrow',$appSettings['organization_name']) @section('page-title','Bank Reconciliation Statement')
@section('page-description',$account->name.' • '.($account->isShared() ? 'All Branches — Shared (custodian: '.$account->branch->name.')' : $account->branch->name).' • as at '.$reconciliation->statement_date->format('d M Y').' • '.$reconciliation->statusLabel())
@section('page-actions')
<div class="d-flex gap-2">
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
    @can('finance_reports.export')@include('accounting.reports._export-links', ['exportRoute' => 'bank-reconciliations.export', 'exportParameters' => ['bankReconciliation' => $reconciliation->id]])@endcan
    <a class="btn btn-outline-primary" href="{{ route('bank-reconciliations.show', $reconciliation) }}">Back</a>
</div>
@endsection
@section('content')
<section class="surface-card">
    <div class="row g-3 mb-3">
        <div class="col-md-3"><small>Bank account</small><strong class="d-block">{{ $account->name }}</strong><span class="table-subtext">{{ $account->bank_name }} {{ $account->maskedNumber() }}</span></div>
        @if($account->isShared())
            <div class="col-md-3"><small>Account scope</small><strong class="d-block">All Branches — Shared</strong><span class="table-subtext">Custodian branch: {{ $account->branch->name }}</span></div>
        @else
            <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $account->branch->name }}</strong></div>
        @endif
        <div class="col-md-3"><small>Statement date</small><strong class="d-block">{{ $reconciliation->statement_date->format('d M Y') }}</strong></div>
        <div class="col-md-3"><small>Currency</small><strong class="d-block">{{ $appSettings['currency_code'] }}</strong></div>
    </div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Particulars</th><th>Date</th><th>Voucher / Reference</th><th class="text-end">Amount</th></tr></thead><tbody>
        <tr><th>Balance as per bank statement</th><td>{{ $reconciliation->statement_date->format('d M Y') }}</td><td></td><th class="text-end font-monospace">{{ $amount($figures['statement_balance']) }}</th></tr>
        <tr><th colspan="4">Add: uncleared deposits</th></tr>
        @forelse($figures['uncleared'] as $line)
            <tr><td class="ps-4">{{ $line->description ?: $line->journalEntry->narration }}</td><td>{{ $line->journalEntry->transaction_date->format('d M Y') }}</td><td>{{ $line->journalEntry->voucher_number }}</td><td class="text-end font-monospace">{{ $amount($bookAmount($line)) }}</td></tr>
        @empty
            <tr><td class="ps-4" colspan="4">None</td></tr>
        @endforelse
        <tr><td>Total uncleared deposits</td><td></td><td></td><td class="text-end font-monospace">{{ $amount($figures['uncleared_total']) }}</td></tr>
        <tr><th colspan="4">Less: unpresented payments</th></tr>
        @forelse($figures['unpresented'] as $line)
            <tr><td class="ps-4">{{ $line->description ?: $line->journalEntry->narration }}</td><td>{{ $line->journalEntry->transaction_date->format('d M Y') }}</td><td>{{ $line->journalEntry->voucher_number }}</td><td class="text-end font-monospace">{{ $amount($bookAmount($line)) }}</td></tr>
        @empty
            <tr><td class="ps-4" colspan="4">None</td></tr>
        @endforelse
        <tr><td>Total unpresented payments</td><td></td><td></td><td class="text-end font-monospace">({{ $amount($figures['unpresented_total']) }})</td></tr>
        <tr><th>Adjusted bank balance</th><td></td><td></td><th class="text-end font-monospace">{{ $amount($figures['adjusted_balance']) }}</th></tr>
        <tr><th>Balance as per bank book</th><td>{{ $reconciliation->statement_date->format('d M Y') }}</td><td></td><th class="text-end font-monospace">{{ $amount($figures['book_balance']) }}</th></tr>
        <tr><th>Difference</th><td></td><td></td><th class="text-end font-monospace">{{ $amount($figures['difference']) }}</th></tr>
        @if($figures['unrecorded']->isNotEmpty())
            <tr><th colspan="4">Statement items not yet recorded in the books</th></tr>
            @foreach($figures['unrecorded'] as $line)
                <tr><td class="ps-4">{{ $line->description }}</td><td>{{ $line->transaction_date->format('d M Y') }}</td><td>{{ $line->reference }}</td><td class="text-end font-monospace">{{ $amount($line->amount) }}</td></tr>
            @endforeach
        @endif
    </tbody></table></div>
    <p class="card-copy">{{ $formula }}.</p>
    <div class="row g-3">
        <div class="col-md-4"><small>Prepared by</small><strong class="d-block">{{ $reconciliation->preparer?->name ?? '—' }}</strong></div>
        <div class="col-md-4"><small>Submitted by</small><strong class="d-block">{{ $reconciliation->submitter?->name ?? '—' }}</strong></div>
        <div class="col-md-4"><small>Approved by</small><strong class="d-block">{{ $reconciliation->approver?->name ?? '—' }}</strong><span class="table-subtext">{{ $reconciliation->approved_at?->format('d M Y H:i') }}</span></div>
    </div>
</section>
@endsection
