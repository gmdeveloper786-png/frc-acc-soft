@extends('layouts.app') @section('title','Fee Settlement Mapping') @section('page-title','FRC Fee Settlement Mapping')
@section('content')
@include('fee-posting.partials.banners')
<p class="text-muted">Choose, per branch, the cash or bank account each FRC payment method settles into. Cash must map to a cash account; every other method to a bank-type account of the same branch (wallet and card clearing accounts are set up as bank-type accounts). Card, Easypaisa and JazzCash are mapped separately.</p>
@forelse($branches as $branch)
    @php($missing = collect($rows[$branch->id])->filter(fn ($row) => ! $row['mapping'] || $row['issue'])->count())
    <div class="card mb-3"><div class="card-body">
        <h2 class="h5">{{ $branch->name }} @if($missing)<span class="status-badge pending">{{ $missing }} missing or invalid</span>@else<span class="status-badge success">Complete</span>@endif</h2>
        <div class="table-responsive"><table class="table align-middle">
            <thead><tr><th>Payment method</th><th>Requires</th><th>Current account</th><th></th></tr></thead>
            <tbody>
            @foreach($rows[$branch->id] as $method => $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['required_type'] }} account</td>
                    <td>
                        @if($row['mapping'])
                            {{ $row['mapping']->financialAccount?->name }} <span class="text-muted small">{{ $row['mapping']->financialAccount?->account?->code }} {{ $row['mapping']->financialAccount?->maskedNumber() }}</span>
                            @if($row['issue'])<div class="small text-danger">Invalid: {{ $row['issue'] }}</div>@endif
                        @else
                            <span class="text-danger">Not mapped — payments by this method cannot be posted.</span>
                        @endif
                    </td>
                    <td>
                        @if($canManage)
                            <form method="POST" action="{{ route('fee-settlement-mappings.update') }}" class="d-flex gap-2">@csrf @method('PUT')
                                <input type="hidden" name="branch_id" value="{{ $branch->id }}"><input type="hidden" name="payment_method" value="{{ $method }}">
                                <select class="form-select form-select-sm" name="financial_account_id" aria-label="Account for {{ $row['label'] }}">
                                    <option value="">— not mapped —</option>
                                    @foreach($row['options'] as $option)<option value="{{ $option->id }}" @selected($row['mapping']?->financial_account_id === $option->id)>{{ $option->name }} ({{ $option->account?->code }})</option>@endforeach
                                </select>
                                <button class="btn btn-sm btn-primary">Save</button>
                            </form>
                            @if($row['options']->isEmpty())<div class="small text-muted">No active {{ $row['required_type'] }} account with a valid ledger exists for this branch.</div>@endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div></div>
@empty
    <div class="card"><div class="card-body text-center py-5">You have no branches assigned.</div></div>
@endforelse
{{ $branches->links() }}
@endsection
