@extends('layouts.app')
@section('title', 'Payee Directory')
@section('eyebrow', 'Accounting Setup')
@section('page-title', 'Payee Directory')
@section('page-description', 'Recipients that are neither vendors nor employees. Vendors and employees are chosen from their own masters on Create Payment.')
@section('page-actions')
    @can('vendors.create')<a class="btn btn-primary" href="{{ route('payees.create') }}">Add payee</a>@endcan
@endsection

@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Search name or reference">
        <select class="form-select" name="payee_type_id" aria-label="Payee type"><option value="">All payee types</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected((string) request('payee_type_id') === (string) $type->id)>{{ $type->name }}</option>@endforeach</select>
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
        <button class="btn btn-primary">Filter</button>
    </form>
    <div class="table-responsive"><table class="table accounting-table">
        <thead><tr><th>Payee</th><th>Payee type</th><th>Kind</th><th>Contact</th><th>Reference</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($payees as $payee)
            <tr>
                <td><strong>{{ $payee->name }}</strong></td>
                <td>{{ $payee->payeeType->name }}</td>
                <td>{{ \App\Models\Payee::ENTITY_KINDS[$payee->entity_kind] ?? $payee->entity_kind }}</td>
                <td>{{ $payee->phone }}@if($payee->email)<span class="table-subtext">{{ $payee->email }}</span>@endif</td>
                <td>{{ $payee->reference ?? '—' }}</td>
                <td><span class="status-badge {{ $payee->is_active ? 'success' : 'danger' }}">{{ $payee->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>
                    <div class="d-flex gap-1">
                        @can('vendors.update')<a class="btn btn-sm btn-outline-primary" href="{{ route('payees.edit', $payee) }}">Edit</a>@endcan
                        @can('vendors.deactivate')<form method="POST" action="{{ route('payees.toggle', $payee) }}">@csrf<button class="btn btn-sm btn-outline-{{ $payee->is_active ? 'danger' : 'success' }}">{{ $payee->is_active ? 'Deactivate' : 'Activate' }}</button></form>@endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7"><x-empty-state title="No payees" description="Add recipients such as landlords, consultants or authorities." /></td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $payees->links() }}
</section>
@endsection
