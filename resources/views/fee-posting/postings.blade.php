@extends('layouts.app') @section('title','Posted Fee Receipts') @section('page-title','Posted FRC Fee Receipts')
@section('content')
@include('fee-posting.partials.banners')
<div class="card mb-3"><div class="card-body">
    <form class="row g-2" method="GET">
        <div class="col-md-4"><select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><select class="form-select" name="kind"><option value="">Payments and recognitions</option><option value="payment" @selected(request('kind') === 'payment')>Payments</option><option value="enrollment" @selected(request('kind') === 'enrollment')>Enrollment recognitions</option></select></div>
        <div class="col-md-3"><select class="form-select" name="status"><option value="">All statuses</option><option value="posted" @selected(request('status') === 'posted')>Posted</option><option value="reversed" @selected(request('status') === 'reversed')>Reversed</option></select></div>
        <div class="col-md-2"><button class="btn btn-primary">Filter</button></div>
    </form>
</div></div>
<div class="card"><div class="card-body">
    <div class="table-responsive"><table class="table align-top">
        <thead><tr><th>Date</th><th>Record</th><th>Branch</th><th>Service</th><th>Basis</th><th class="text-end">Amount</th><th>Journal</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($postings as $posting)
            @php($journal = $posting->journalEntry)
            <tr>
                <td>{{ $journal->transaction_date?->format('d M Y') }}</td>
                <td>@if($posting->kind === 'payment'){{ $posting->payment?->receipt_number ?? 'FRC payment' }} <span class="text-muted small">payment</span>@else FRC enrollment #{{ $posting->enrollment?->frc_enrollment_id }} <span class="text-muted small">recognition</span>@endif<div class="small text-muted">{{ $posting->source_key }}</div></td>
                <td>{{ $journal->branch?->name }}</td>
                <td>{{ ($posting->kind === 'payment' ? $posting->payment?->service?->name : $posting->enrollment?->service?->name) ?? '—' }}</td>
                <td>{{ $posting->basis }}</td>
                <td class="text-end">{{ number_format((float) $posting->amount, 2) }}</td>
                <td>@can('journals.view')<a href="{{ route('journals.show', $journal) }}">{{ $journal->voucher_number }}</a>@else{{ $journal->voucher_number }}@endcan
                    @if($posting->reversalJournalEntry)<div class="small">Reversed by @can('journals.view')<a href="{{ route('journals.show', $posting->reversalJournalEntry) }}">{{ $posting->reversalJournalEntry->voucher_number }}</a>@else{{ $posting->reversalJournalEntry->voucher_number }}@endcan</div>@endif</td>
                <td><span class="status-badge {{ $posting->status === 'posted' ? 'success' : 'pending' }}">{{ $posting->status }}</span>@if($posting->kind === 'payment' && $posting->payment?->posting_status === 'needs_review')<div class="small text-danger">Needs review</div>@endif @if($posting->reversal_reason)<div class="small text-muted">{{ $posting->reversal_reason }}</div>@endif</td>
                <td>
                    @if($canReverse && $posting->status === 'posted')
                        <form method="POST" action="{{ route('fee-posting.postings.reverse', $posting) }}" class="d-grid gap-1" style="min-width: 14rem">@csrf
                            <select class="form-select form-select-sm" name="accounting_period_id" required><option value="">Open period</option>@foreach($periods as $period)<option value="{{ $period->id }}">{{ $period->name }} ({{ $period->fiscalYear?->name }})</option>@endforeach</select>
                            <input class="form-control form-control-sm" type="date" name="transaction_date" required>
                            <input class="form-control form-control-sm" name="reason" placeholder="Reason (min 5 characters)" minlength="5" required>
                            <button class="btn btn-sm btn-outline-danger" data-confirm="Reverse this posting? An opposite journal is posted; the original stays unchanged.">Reverse</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center py-5">No FRC fees have been posted yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $postings->links() }}
</div></div>
@endsection
