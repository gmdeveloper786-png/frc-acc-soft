@extends('layouts.app')
@section('title', 'Payment Purposes')
@section('eyebrow', 'Accounting Setup')
@section('page-title', 'Payment Purposes')
@section('page-description', 'Business purposes offered on Create Payment. Each one uses a protected accounting behaviour; it cannot define its own debit and credit rules.')
@section('page-actions')
    @can('account_mappings.manage')<a class="btn btn-primary" href="{{ route('payment-purposes.create') }}">Add purpose</a>@endcan
@endsection

@section('content')
<section class="surface-card">
    <form class="filter-bar" method="GET">
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
        <button class="btn btn-primary">Filter</button>
    </form>
    <div class="table-responsive"><table class="table accounting-table">
        <thead><tr><th>Order</th><th>Purpose</th><th>Code</th><th>Accounting behaviour</th><th>Debit ledger</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($purposes as $purpose)
            <tr>
                <td>{{ $purpose->sort_order }}</td>
                <td><strong>{{ $purpose->name }}</strong>@if($purpose->description)<span class="table-subtext">{{ $purpose->description }}</span>@endif</td>
                <td><code>{{ $purpose->code }}</code></td>
                <td>{{ $purpose->behaviour->label() }}</td>
                <td>{{ $purpose->debitAccount ? $purpose->debitAccount->code.' — '.$purpose->debitAccount->name : ($purpose->behaviour->usesDebitLedger() ? 'Chosen on the payment' : 'From the record') }}</td>
                <td><span class="status-badge {{ $purpose->is_active ? 'success' : 'danger' }}">{{ $purpose->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>
                    @can('account_mappings.manage')
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('payment-purposes.edit', $purpose) }}">Edit</a>
                            <form method="POST" action="{{ route('payment-purposes.toggle', $purpose) }}">@csrf<button class="btn btn-sm btn-outline-{{ $purpose->is_active ? 'danger' : 'success' }}">{{ $purpose->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                        </div>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="7"><x-empty-state title="No payment purposes" description="Add a purpose to offer it on Create Payment." /></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
