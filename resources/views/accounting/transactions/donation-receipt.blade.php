@php
    $isDonation = $transaction->type === \App\Enums\OperationalTransactionType::Donation;
    $receiptTitle = $isDonation ? 'Donation Acknowledgment' : $transaction->type->label();
    $isProjectFund = $transaction->fund && (
        $transaction->donationCategory?->slug === 'new-frc-project-donation'
        || $transaction->fund->isProjectChildOfDnr()
        || $transaction->fund->code === 'DNR'
        || $transaction->fund->parent?->code === 'DNR'
        || $transaction->fund->category === 'donor'
    );
@endphp
@extends('layouts.app') @section('title',$receiptTitle) @section('page-title',$receiptTitle) @section('page-header-class', 'd-print-none')
@section('page-actions')<button class="btn btn-primary" onclick="window.print()">Print</button>@endsection
@section('content')
<style>
@media print {
    @page { size: portrait; margin: 10mm; }
    .receipt-card { max-width: 100% !important; border: 1px solid #dee2e6 !important; box-shadow: none !important; page-break-inside: avoid; break-inside: avoid; }
    .receipt-card-body { padding: 2rem !important; }
}
</style>
<div class="card mx-auto receipt-card" style="max-width:760px">
    <div class="card-body p-5 text-center receipt-card-body">
        <img src="{{ asset($appSettings['logo_path']) }}" alt="FRC" style="max-height:70px">
        <h2 class="h4 mt-3">{{ $appSettings['organization_name'] }}</h2>
        <p>{{ $receiptTitle }}</p>
        <hr>
        <h3 class="text-primary">{{ $appSettings['currency_code'] }} {{ number_format((float)$transaction->amount,2) }}</h3>
        <dl class="row text-start mt-4">
            <dt class="col-5">Receipt number</dt>
            <dd class="col-7">{{ $transaction->journalEntry->voucher_number }}</dd>
            <dt class="col-5">Date</dt>
            <dd class="col-7">{{ $transaction->transaction_date->format('d M Y') }}</dd>
            @if($isDonation)
                <dt class="col-5">Donor</dt>
                <dd class="col-7">{{ $transaction->party_name?:'Anonymous' }}</dd>
                <dt class="col-5">Category</dt>
                <dd class="col-7">{{ $transaction->donationCategory->name }}</dd>
            @else
                <dt class="col-5">Received from</dt>
                <dd class="col-7">{{ $transaction->party_name?:'—' }}</dd>
                <dt class="col-5">For</dt>
                <dd class="col-7">{{ $transaction->narration ?: '—' }}</dd>
            @endif
            @if($transaction->fund)
                <dt class="col-5">{{ $isProjectFund ? 'Project / Restricted Fund' : ($isDonation ? 'Fund' : 'Fund Allocation') }}</dt>
                <dd class="col-7">{{ $transaction->fund->label() }}</dd>
            @endif
            <dt class="col-5">Branch</dt>
            <dd class="col-7">{{ $transaction->branch->name }}</dd>
            <dt class="col-5">Mode</dt>
            <dd class="col-7">{{ ucfirst($transaction->financialAccount->type) }}</dd>
            <dt class="col-5">Reference</dt>
            <dd class="col-7">{{ $transaction->reference_number?:'—' }}</dd>
        </dl>
        <hr>
        <small>This is a system-generated {{ $isDonation ? 'acknowledgment for a posted donation receipt' : 'receipt for a posted '.strtolower($transaction->type->label()) }}.</small>
    </div>
</div>
@endsection
