@extends('layouts.app') @section('title','Year-end closing — '.$fiscalYear->name) @section('eyebrow','Accounting Setup') @section('page-title','Year-end closing: '.$fiscalYear->name)
@section('page-description','Moves each branch\'s income and expenditure balance for the year to the Accumulated Surplus ledger with one posted JV per branch dated '.$fiscalYear->end_date->format('d M Y').'. A closing is undone only by reversing its journals.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('fiscal-years.show',$fiscalYear) }}">Back to fiscal year</a>@endsection
@section('content')
@if($preview['blockers'] !== [])
<div class="alert alert-warning" role="alert"><strong>The closing entry cannot be posted yet:</strong><ul class="mb-0">@foreach($preview['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
@endif

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Preview</span><h2>Income and expenditure to close</h2></div><span class="status-badge {{ $fiscalYear->status->value === 'open' ? 'success' : 'neutral' }}">{{ ucfirst($fiscalYear->status->value) }}</span></div>
    <p class="card-copy">Accumulated Surplus ledger:
        @if($preview['accumulated_surplus'])<code>{{ $preview['accumulated_surplus']->code }}</code> {{ $preview['accumulated_surplus']->name }}@else <span class="status-badge danger">Not mapped</span>@endif
        @can('account_mappings.view') · <a href="{{ route('account-mappings.index') }}">GL Mapping</a>@endcan
    </p>
    @forelse($preview['branches'] as $branch)
        <h3 class="section-title mt-3">{{ $branch['branch']->name }}</h3>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Ledger</th><th>Type</th><th class="text-end">Balance (Dr − Cr)</th><th class="text-end">Closing debit</th><th class="text-end">Closing credit</th></tr></thead><tbody>
        @foreach($branch['lines'] as $line)
            @php($net = \Brick\Math\BigDecimal::of($line['net']))
            <tr><td><code>{{ $line['account']->code }}</code> {{ $line['account']->name }}</td><td>{{ ucfirst($line['account']->account_type->value) }}</td><td class="text-end font-monospace">{{ $line['net'] }}</td><td class="text-end font-monospace">{{ $net->isNegative() ? (string) $net->abs() : '—' }}</td><td class="text-end font-monospace">{{ $net->isPositive() ? $line['net'] : '—' }}</td></tr>
        @endforeach
        </tbody><tfoot>
            <tr><th colspan="2">Total income</th><th class="text-end font-monospace" colspan="3">{{ $branch['total_income'] }}</th></tr>
            <tr><th colspan="2">Total expenditure</th><th class="text-end font-monospace" colspan="3">{{ $branch['total_expenses'] }}</th></tr>
            <tr><th colspan="2">Surplus/(deficit) to Accumulated Surplus</th><th class="text-end font-monospace" colspan="3">{{ $branch['surplus'] }}</th></tr>
        </tfoot></table></div>
    @empty
        <x-empty-state title="Nothing to close" description="No branch you can see has a posted income or expenditure balance for this fiscal year." />
    @endforelse
    @if($preview['branches'] !== [])
        <div class="table-responsive"><table class="table accounting-table"><tbody>
            <tr><th>All branches shown: total income</th><td class="text-end font-monospace">{{ $preview['total_income'] }}</td></tr>
            <tr><th>Total expenditure</th><td class="text-end font-monospace">{{ $preview['total_expenses'] }}</td></tr>
            <tr><th>Net surplus/(deficit)</th><td class="text-end font-monospace">{{ $preview['surplus'] }}</td></tr>
        </tbody></table></div>
    @endif
</section>

@if($preview['can_post'] && $preview['blockers'] === [])
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Organisation level</span><h2>Post year-end closing</h2></div></div>
    <form class="row g-2" method="POST" action="{{ route('fiscal-years.closing.store',$fiscalYear) }}" data-submit-once onsubmit="return window.confirm('Post the year-end closing journals for {{ $fiscalYear->name }}? Income and expenditure ledgers of every branch will be closed to the Accumulated Surplus ledger.')">@csrf
        <div class="col-md-9"><label class="form-label" for="closing-reason">Reason</label><input class="form-control" id="closing-reason" name="reason" required minlength="10" maxlength="500" value="{{ old('reason') }}" placeholder="e.g. Annual closing approved by the director"></div>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">Post closing entries</button></div>
    </form>
</section>
@elseif(! $preview['can_post'])
<div class="alert alert-info">Posting the closing needs the fiscal year close, journal post and all-branches permissions.</div>
@endif

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">History</span><h2>Closing journals</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Voucher</th><th>Branch</th><th>Date</th><th class="text-end">Amount</th><th>Status</th></tr></thead><tbody>
    @forelse($preview['closing_journals'] as $journal)
        <tr><td>@can('journals.view')<a href="{{ route('journals.show',$journal) }}">{{ $journal->voucher_number ?? 'Unposted' }}</a>@else{{ $journal->voucher_number ?? 'Unposted' }}@endcan</td><td>{{ $journal->branch->name }}</td><td>{{ $journal->transaction_date->format('d M Y') }}</td><td class="text-end font-monospace">{{ $journal->total_debit }}</td><td><span class="status-badge {{ $journal->status->value === 'posted' ? 'success' : 'neutral' }}">{{ ucfirst($journal->status->value) }}</span></td></tr>
    @empty
        <tr><td colspan="5"><x-empty-state title="No closing journals" description="No year-end closing has been posted for this fiscal year." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
