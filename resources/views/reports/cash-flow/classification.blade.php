@extends('layouts.app')
@section('title', 'Cash Flow Classification')
@section('eyebrow', 'Financial statements')
@section('page-title', 'Cash Flow Classification')
@section('page-description', 'Assign each ledger to operating, investing or financing activities. The Cash Flow Statement uses only this configuration; nothing is guessed from account names or types.')
@section('page-actions')
    @can('financial_statements.view')<a class="btn btn-outline-primary" href="{{ route('cash-flow.statement') }}">Cash Flow Statement</a>@endcan
@endsection
@section('content')
@error('activity')<div class="alert alert-danger">{{ $message }}</div>@enderror
@if($chartIsEmpty)
    <section class="surface-card"><x-empty-state title="Accounting setup required" description="The Chart of Accounts is empty. Ledgers can be classified after the chart has been imported." /></section>
@else
<section class="surface-card">
    <div class="card-heading">
        <div><span class="section-kicker">Active postable ledgers · cash and bank ledgers excluded</span><h2>Ledger classification</h2></div>
        <span class="status-badge {{ $unclassifiedCount === 0 ? 'success' : 'pending' }}">{{ $unclassifiedCount === 0 ? 'All ledgers classified' : $unclassifiedCount.' unclassified' }}</span>
    </div>
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Account code or name" aria-label="Search ledgers">
        <select class="form-select" name="account_type" aria-label="Account type"><option value="">All account types</option>@foreach($accountTypes as $type)<option value="{{ $type->value }}" @selected(request('account_type') === $type->value)>{{ ucfirst($type->value) }}</option>@endforeach</select>
        <select class="form-select" name="activity" aria-label="Classification"><option value="">All classifications</option><option value="unclassified" @selected(request('activity') === 'unclassified')>Unclassified</option>@foreach($activities as $activity)<option value="{{ $activity->value }}" @selected(request('activity') === $activity->value)>{{ $activity->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('cash-flow.classification.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table">
        <thead><tr><th>Ledger</th><th>Account type</th><th>Classification</th><th>Last changed</th>@can('cash_flow.manage_classification')<th>Set activity</th>@endcan</tr></thead>
        <tbody>
        @forelse($ledgers as $ledger)
            @php $classification = $classifications->get($ledger->id); @endphp
            <tr>
                <td><code>{{ $ledger->code }}</code> {{ $ledger->name }}</td>
                <td>{{ ucfirst($ledger->account_type->value) }}</td>
                <td>@if($classification)<span class="status-badge success">{{ $classification->activity->label() }}</span>@else<span class="status-badge pending">Unclassified</span>@endif</td>
                <td>@if($classification){{ $classification->updated_at->format('d M Y H:i') }}<span class="table-subtext">{{ $classification->updater?->name ?? 'Unknown user' }}</span>@else<span class="table-subtext">Never classified</span>@endif</td>
                @can('cash_flow.manage_classification')
                <td>
                    <form class="d-flex gap-2" method="POST" action="{{ route('cash-flow.classification.update', $ledger) }}" data-submit-once>
                        @csrf @method('PUT')
                        <select class="form-select form-select-sm" name="activity" aria-label="Activity for {{ $ledger->code }}">
                            <option value="">Not classified</option>
                            @foreach($activities as $activity)<option value="{{ $activity->value }}" @selected($classification?->activity === $activity)>{{ $activity->label() }}</option>@endforeach
                        </select>
                        <button class="btn btn-sm btn-primary" data-confirm="Save the cash-flow classification of {{ $ledger->code }}?">Save</button>
                    </form>
                </td>
                @endcan
            </tr>
        @empty
            <tr><td colspan="5"><x-empty-state title="No ledgers match" description="No active postable ledger matches the current filters. Cash, bank and petty cash ledgers are never listed because they are the cash position itself." /></td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $ledgers->links() }}
</section>
@endif
@endsection
