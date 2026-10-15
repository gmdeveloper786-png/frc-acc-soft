@extends('layouts.app') @section('title','Petty Cash Imprest') @section('page-title','Petty Cash Imprest')
@section('page-description','Each branch keeps a fixed petty cash float. Balances come from posted journal lines of the petty cash ledger in that branch; replenishments are CV journals that go through maker-checker approval.')
@section('page-actions') @can('imprest.manage')<a class="btn btn-primary" href="{{ route('imprest.create') }}">Set up float</a>@endcan @endsection
@section('content')
<div class="card"><div class="card-body">
<div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Branch</th><th>Petty cash ledger</th><th>Custodian</th><th class="text-end">Float</th><th class="text-end">Posted balance</th><th class="text-end">Replenishment due</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($floats as $float)
        <tr>
            <td class="fw-semibold">{{ $float->branch->name }}</td>
            <td>{{ $float->account->code }} — {{ $float->account->name }}<span class="table-subtext">Source: {{ $float->sourceAccount?->name }}</span></td>
            <td>{{ $float->custodian_name }}</td>
            <td class="text-end font-monospace">{{ number_format((float) $float->float_amount, 2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float) $figures[$float->id]['balance'], 2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float) $figures[$float->id]['due'], 2) }}</td>
            <td><span class="status-badge {{ $float->is_active ? 'success' : 'neutral' }}">{{ $float->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="{{ route('imprest.show', $float) }}">Open</a> @can('imprest.manage')<a class="btn btn-sm btn-outline-secondary" href="{{ route('imprest.edit', $float) }}">Edit</a>@endcan</td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No imprest floats" description="No petty cash float is set up for your branches yet. A float names the petty cash ledger, the amount the custodian holds and the bank or cash account replenishments come from." /></td></tr>
    @endforelse
    </tbody>
</table></div>
{{ $floats->links() }}
</div></div>
@endsection
