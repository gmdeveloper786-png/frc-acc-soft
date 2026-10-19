@extends('layouts.app')
@section('title','Journal Entries')
@section('page-title','Journal Entries')
@section('page-description','Draft, approve, post and reverse auditable double-entry journals.')
@section('page-actions')@can('journals.create')<a class="btn btn-primary" href="{{ route('journals.create') }}">New Journal</a>@endcan @endsection
@section('content')
<div class="surface-card">
    <form class="filter-bar" method="GET"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Voucher, reference or narration"><select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Enums\JournalStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ str($status->value)->headline() }}</option>@endforeach</select><button class="btn btn-primary">Filter</button></form>
    <div class="table-responsive"><table class="table accounting-table align-middle"><thead><tr><th>Date</th><th>Voucher</th><th>Branch</th><th>Narration</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($journals as $journal)<tr><td>{{ $journal->transaction_date->format('d M Y') }}</td><td>{{ $journal->displayLabel() }}</td><td>{{ $journal->branch->name }}</td><td>{{ str($journal->narration)->limit(55) }}</td><td class="text-end font-monospace">{{ number_format((float)$journal->total_debit,4) }}</td><td class="text-end font-monospace">{{ number_format((float)$journal->total_credit,4) }}</td><td><span class="status-badge {{ $journal->status === \App\Enums\JournalStatus::Reversed ? 'danger' : 'neutral' }}">{{ $journal->status->label() }}</span></td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('journals.show',$journal) }}">View</a></td></tr>
    @empty<tr><td colspan="8"><div class="empty-state"><h3>No journals yet</h3><p>Create a draft after approved ledger accounts are available.</p></div></td></tr>@endforelse
    </tbody></table></div>{{ $journals->links() }}
</div>
@endsection
