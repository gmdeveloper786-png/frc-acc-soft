@extends('layouts.app') @section('title','Imprest Float') @section('eyebrow','Petty Cash Imprest') @section('page-title', $float->label())
@section('page-description','Custodian: '.$float->custodian_name.'. Balance is the posted balance of the petty cash ledger in this branch; replenishment due is the float less that balance.')
@section('page-actions')
<div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="{{ route('imprest.statement', $float) }}">Statement</a>
    @can('imprest.manage')<a class="btn btn-outline-secondary" href="{{ route('imprest.edit', $float) }}">Edit float</a>@endcan
</div>
@endsection
@section('content')
@php($currency = $appSettings['currency_code'])
@unless($float->is_active)<div class="alert alert-secondary">This float is inactive. It cannot be replenished until it is activated again.</div>@endunless
@foreach($summary['warnings'] as $warning)<div class="alert alert-warning" role="alert">{{ $warning }}</div>@endforeach
@foreach(['float', 'amount', 'transaction_date'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<div class="kpi-grid">
    <x-kpi-card label="Imprest float" value="{{ $currency }} {{ number_format((float) $summary['float'], 2) }}" icon="F" tone="navy" status="Set by user" description="Fixed amount the custodian holds"/>
    <x-kpi-card label="Petty cash balance" value="{{ $currency }} {{ number_format((float) $summary['balance'], 2) }}" icon="B" tone="teal" status="Posted ledger" description="{{ $float->account->code }} in {{ $float->branch->name }}"/>
    <x-kpi-card label="Replenishment due" value="{{ $currency }} {{ number_format((float) $summary['due'], 2) }}" icon="R" tone="amber" status="Float − balance" description="Never below zero"/>
    <x-kpi-card label="Vouchers since last replenishment" value="{{ $currency }} {{ number_format((float) $summary['posted_total'], 2) }}" icon="V" tone="rose" status="{{ $summary['posted_vouchers']->count() }} posted" description="Pending (unposted): {{ $currency }} {{ number_format((float) $summary['pending_total'], 2) }}"/>
</div>

<section class="surface-card mb-4">
    <h2 class="h5">Replenishment</h2>
    @if($open = $summary['open_replenishment'])
        <p>Replenishment #{{ $open->id }} of {{ $currency }} {{ number_format((float) $open->amount, 2) }} is <span class="status-badge pending">{{ ucfirst($open->status) }}</span>.
            @if($open->journalEntry)@can('journals.view')<a href="{{ route('journals.show', $open->journalEntry) }}">Open the CV journal</a>@endcan @endif
            It must be submitted, approved by another user and posted before another replenishment can be prepared.</p>
    @elseif(! $float->is_active)
        <p class="text-muted">Inactive floats cannot be replenished.</p>
    @elseif($summary['due'] === '0.0000')
        <p class="text-muted">No replenishment is due.</p>
    @else
        @can('imprest.replenish')
            <form class="row g-3" method="POST" action="{{ route('imprest.replenishments.store', $float) }}">@csrf
                <div class="col-md-3"><label class="form-label" for="transaction_date">Journal date</label><input class="form-control" type="date" id="transaction_date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required></div>
                <div class="col-md-3"><label class="form-label" for="amount">Amount (blank = full due)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" placeholder="{{ $summary['due'] }}"></div>
                <div class="col-md-6 d-flex align-items-end"><button class="btn btn-primary">Prepare draft CV journal</button></div>
                <div class="col-12"><small class="text-muted">Dr {{ $float->account->code }} {{ $float->account->name }} / Cr {{ $float->sourceAccount?->name }} ({{ $float->sourceAccount?->account?->code }}). Nothing is posted here: the journal follows the normal submit, approve and post workflow.</small></div>
            </form>
        @else
            <p class="text-muted">A user with replenishment permission prepares the replenishment journal.</p>
        @endcan
    @endif
</section>

<section class="surface-card mb-4">
    <h2 class="h5">Posted petty cash vouchers since the last replenishment</h2>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Date</th><th>Voucher</th><th>Party</th><th>Narration</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        @forelse($summary['posted_vouchers'] as $voucher)
            <tr><td>{{ $voucher->transaction_date->format('d M Y') }}</td><td>@can('petty_cash.view')<a href="{{ route('transactions.show', $voucher) }}">{{ $voucher->journalEntry?->voucher_number ?? '#'.$voucher->id }}</a>@else{{ $voucher->journalEntry?->voucher_number ?? '#'.$voucher->id }}@endcan</td><td>{{ $voucher->party_name ?: '—' }}</td><td>{{ $voucher->narration }}</td><td class="text-end font-monospace">{{ number_format((float) $voucher->amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="5" class="text-center py-4">No posted petty cash vouchers since the last replenishment.</td></tr>
        @endforelse
        </tbody>
        @if($summary['posted_vouchers']->isNotEmpty())<tfoot><tr><th colspan="4">Total</th><th class="text-end font-monospace">{{ number_format((float) $summary['posted_total'], 2) }}</th></tr></tfoot>@endif
    </table></div>
</section>

<section class="surface-card mb-4">
    <h2 class="h5">Pending (unposted) petty cash vouchers</h2>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Date</th><th>Ref</th><th>Status</th><th>Narration</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        @forelse($summary['pending_vouchers'] as $voucher)
            <tr><td>{{ $voucher->transaction_date->format('d M Y') }}</td><td>@can('petty_cash.view')<a href="{{ route('transactions.show', $voucher) }}">#{{ $voucher->id }}</a>@else#{{ $voucher->id }}@endcan</td><td><span class="status-badge pending">{{ ucfirst($voucher->status->value) }}</span></td><td>{{ $voucher->narration }}</td><td class="text-end font-monospace">{{ number_format((float) $voucher->amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="5" class="text-center py-4">No pending petty cash vouchers.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <small class="text-muted">Pending vouchers do not affect the balance or the replenishment due until they are posted.</small>
</section>

<section class="surface-card">
    <h2 class="h5">Replenishment history</h2>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>#</th><th>Requested</th><th>By</th><th>Vouchers covered</th><th class="text-end">Amount</th><th>Status</th><th>Journal</th></tr></thead>
        <tbody>
        @forelse($replenishments as $replenishment)
            <tr>
                <td>{{ $replenishment->id }}</td><td>{{ $replenishment->created_at->format('d M Y') }}</td><td>{{ $replenishment->creator?->name }}</td>
                <td>{{ count($replenishment->covered_voucher_ids ?? []) }}@if($replenishment->covered_from) <span class="table-subtext">{{ $replenishment->covered_from->format('d M Y') }} – {{ $replenishment->covered_to->format('d M Y') }}</span>@endif</td>
                <td class="text-end font-monospace">{{ number_format((float) $replenishment->amount, 2) }}</td>
                <td><span class="status-badge {{ $replenishment->status === 'posted' ? 'success' : ($replenishment->isOpen() ? 'pending' : 'neutral') }}">{{ ucfirst($replenishment->status) }}</span></td>
                <td>@if($replenishment->journalEntry)@can('journals.view')<a href="{{ route('journals.show', $replenishment->journalEntry) }}">{{ $replenishment->journalEntry->displayLabel() }}</a>@else{{ $replenishment->journalEntry->displayLabel() }}@endcan @else — @endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4">No replenishments yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $replenishments->links() }}
</section>
@endsection
