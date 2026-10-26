@extends('layouts.app') @section('title','Bank Reconciliation') @section('eyebrow','Bank reconciliation') @section('page-title','Bank Reconciliation')
@section('page-description','Reconcile each bank account of your branches with its bank statement. Approved reconciliations are locked.')
@section('page-actions')
<div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="{{ route('bank-statements.index') }}">Bank statements</a>
    {{-- Offered only with the permission and at least one bank account this user may reconcile. --}}
    @if(auth()->user()->can('bank_reconciliation.prepare') && $accounts->isNotEmpty())<a class="btn btn-primary" href="{{ route('bank-reconciliations.create') }}">+ New reconciliation</a>@endif
</div>
@endsection
@section('content')
@php
    $amount = fn ($value) => \App\Services\BankReconciliation\Amount::format($value);
@endphp
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Bank Accounts — Branch-Specific &amp; Shared</span><h2>Bank accounts</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Bank account</th><th>Branch</th><th>Bank</th><th>Last approved statement date</th><th>In progress</th><th>Actions</th></tr></thead><tbody>
    @forelse($accounts as $account)
        @php $state = $latest->get($account->id); @endphp
        <tr>
            <td>{{ $account->name }}@if($account->maskedNumber())<span class="table-subtext">{{ $account->maskedNumber() }}</span>@endif</td>
            <td>@if($account->isShared())<span class="status-badge neutral">All Branches — Shared</span><span class="table-subtext">Custodian: {{ $account->branch->name }}</span>@else{{ $account->branch->name }}@endif</td>
            <td>{{ $account->bank_name ?? '—' }}</td>
            <td>{{ $state?->last_approved ? \Illuminate\Support\Carbon::parse($state->last_approved)->format('d M Y') : 'Never reconciled' }}</td>
            <td>@if($state?->open_id)<a href="{{ route('bank-reconciliations.show', $state->open_id) }}">Open reconciliation</a>@else — @endif</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('bank-reconciliations.index', ['financial_account_id' => $account->id]) }}">History</a></td>
        </tr>
    @empty
        <tr><td colspan="6"><x-empty-state title="No bank accounts" description="No bank-type cash/bank account exists in your branches." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Newest statement date first</span><h2>Reconciliations</h2></div></div>
    <form class="filter-bar" method="GET">
        <select class="form-select" name="financial_account_id" aria-label="Bank account"><option value="">All bank accounts</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int) request('financial_account_id') === $account->id)>{{ $account->name }} — {{ $account->branch->name }}</option>@endforeach</select>
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach(\App\Models\BankReconciliation::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('bank-reconciliations.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Statement date</th><th>Bank account</th><th>Branch</th><th class="text-end">Statement balance</th><th>Status</th><th>Prepared by</th><th>Approved by</th><th>Actions</th></tr></thead><tbody>
    @forelse($reconciliations as $reconciliation)
        <tr>
            <td>{{ $reconciliation->statement_date->format('d M Y') }}</td>
            <td>{{ $reconciliation->financialAccount->name }}</td>
            <td>@if($reconciliation->financialAccount->isShared())<span class="status-badge neutral">All Branches — Shared</span><span class="table-subtext">Custodian: {{ $reconciliation->branch->name }}</span>@else{{ $reconciliation->branch->name }}@endif</td>
            <td class="text-end font-monospace">{{ $appSettings['currency_code'] }} {{ $amount($reconciliation->statement_closing_balance) }}</td>
            <td><span class="status-badge {{ $reconciliation->badge() }}">{{ $reconciliation->statusLabel() }}</span></td>
            <td>{{ $reconciliation->preparer?->name ?? '—' }}</td>
            <td>{{ $reconciliation->approver?->name ?? '—' }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('bank-reconciliations.show', $reconciliation) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No reconciliations found" description="Start a reconciliation or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $reconciliations->links() }}
</section>
@endsection
