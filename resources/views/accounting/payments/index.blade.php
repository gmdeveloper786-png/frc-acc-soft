@extends('layouts.app')
@section('title', 'Payments')
@section('page-title', 'Payments')
@section('page-description', 'All outgoing bank, cash, vendor and petty cash payments, including historical paid expenses and transactions created on earlier payment screens.')
@section('page-actions') @if($canCreate)<a class="btn btn-primary" href="{{ route('payments.create') }}">Create Payment</a>@endif @endsection
@php
    $sourceLabels = ['bank' => 'Bank', 'cash' => 'Cash', 'petty_cash' => 'Petty Cash'];
@endphp
@section('content')
<div class="card"><div class="card-body">
<form class="row g-3 mb-4">
    <div class="col-md-3"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Reference, voucher no. or payee" aria-label="Search"></div>
    <div class="col-md-2"><select class="form-select" name="branch_id" aria-label="Branch"><option value="">All accessible branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><select class="form-select" name="source" aria-label="Payment source"><option value="">All sources</option>@foreach($sourceLabels as $value => $label)<option value="{{ $value }}" @selected(request('source') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-2"><select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach(\App\Enums\OperationalTransactionStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ ucfirst($status->value) }}</option>@endforeach</select></div>
    <div class="col-md-3"><select class="form-select" name="purpose_id" aria-label="Payment purpose"><option value="">All purposes</option>@foreach($purposes as $purpose)<option value="{{ $purpose->id }}" @selected(request('purpose_id') == $purpose->id)>{{ $purpose->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><input class="form-control" type="date" name="from" value="{{ request('from') }}" aria-label="From date"></div>
    <div class="col-md-2"><input class="form-control" type="date" name="to" value="{{ request('to') }}" aria-label="To date"></div>
    <div class="col-md-2"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="{{ route('payments.index') }}">Reset</a></div>
</form>
<div class="table-responsive"><table class="table align-middle">
<thead><tr><th>Date</th><th>Reference / voucher</th><th>Source</th><th>Branch</th><th>Payee / recipient</th><th>Purpose</th><th>Account</th><th class="text-end">Amount (PKR)</th><th>Status</th><th></th></tr></thead>
<tbody>
@forelse($payments as $payment)
    @php
        $paymentSource = $payment->type === \App\Enums\OperationalTransactionType::PettyCash ? 'petty_cash' : $payment->financialAccount?->type;
        $payee = data_get($payment->payee_snapshot, 'payee.name') ?? $payment->vendor?->name ?? $payment->party_name;
        $purposeName = $payment->paymentPurpose?->name ?? data_get($payment->payee_snapshot, 'purpose.name');
    @endphp
    <tr data-payment-row="{{ $payment->id }}">
        <td>{{ $payment->transaction_date->format('d M Y') }}</td>
        <td>{{ $payment->reference_number ?: '—' }}@if($payment->journalEntry?->voucher_number)<br><small class="text-muted">{{ $payment->journalEntry->voucher_number }}</small>@endif<br><small class="text-muted">{{ $payment->paymentTypeLabel() }}</small></td>
        <td>{{ $sourceLabels[$paymentSource] ?? '—' }}</td>
        <td>{{ $payment->branch->name }}</td>
        <td>{{ filled($payee) ? $payee : 'Not recorded' }}</td>
        <td>{{ $purposeName ?? 'Not recorded' }}</td>
        <td>{{ $payment->financialAccount?->name ?? '—' }}</td>
        <td class="text-end">{{ number_format((float) $payment->amount, 2) }}</td>
        <td><span class="badge text-bg-light">{{ $payment->status->value }}</span></td>
        <td>
            @if($payment->type === \App\Enums\OperationalTransactionType::Expense)
                @can('expenses.view')
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('transactions.show', $payment) }}">View</a>
                @endcan
            @else
                @can($payment->type->permissionPrefix().'.view')
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('transactions.show', $payment) }}">View</a>
                @endcan
            @endif
        </td>
    </tr>
@empty
    <tr><td colspan="10" class="text-center py-5">No payments found.</td></tr>
@endforelse
</tbody></table></div>
{{ $payments->links() }}
</div></div>
@endsection
