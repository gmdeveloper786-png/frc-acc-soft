@extends('layouts.app')
@section('title', 'Payee Types')
@section('eyebrow', 'Accounting Setup')
@section('page-title', 'Payee Types')
@section('page-description', 'Recipient categories offered on Create Payment. Each type reads its payees from the Vendors master, the Employees master or the Payee Directory.')
@section('page-actions')
    @can('account_mappings.manage')<a class="btn btn-primary" href="{{ route('payee-types.create') }}">Add payee type</a>@endcan
@endsection

@section('content')
<section class="surface-card">
    <div class="table-responsive"><table class="table accounting-table">
        <thead><tr><th>Order</th><th>Payee type</th><th>Code</th><th>Payees come from</th><th>Directory payees</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($types as $type)
            <tr>
                <td>{{ $type->sort_order }}</td>
                <td><strong>{{ $type->name }}</strong>@if($type->description)<span class="table-subtext">{{ $type->description }}</span>@endif</td>
                <td><code>{{ $type->code }}</code></td>
                <td>{{ \App\Models\PayeeType::SOURCES[$type->source_kind] ?? $type->source_kind }}</td>
                <td>{{ $type->source_kind === \App\Models\PayeeType::SOURCE_DIRECTORY ? $type->payees_count : '—' }}</td>
                <td><span class="status-badge {{ $type->is_active ? 'success' : 'danger' }}">{{ $type->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td>
                    @can('account_mappings.manage')
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('payee-types.edit', $type) }}">Edit</a>
                            <form method="POST" action="{{ route('payee-types.toggle', $type) }}">@csrf<button class="btn btn-sm btn-outline-{{ $type->is_active ? 'danger' : 'success' }}">{{ $type->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                        </div>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="7"><x-empty-state title="No payee types" description="Add a payee type to group recipients." /></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
