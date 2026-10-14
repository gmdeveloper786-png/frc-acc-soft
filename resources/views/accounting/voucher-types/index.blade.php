@extends('layouts.app')
@section('title', 'Voucher Types')
@section('page-title', 'Voucher Types')
@section('page-description', 'Database-driven numbering foundations. Only Journal Voucher has a completed business workflow in this milestone.')
@section('content')
<div class="surface-card"><div class="table-responsive"><table class="table accounting-table">
    <thead><tr><th>Code</th><th>Name</th><th>Prefix</th><th>Year</th><th>Padding</th><th>Reset</th><th>Status</th><th>Posted Use</th><th>Action</th></tr></thead>
    <tbody>@foreach($voucherTypes as $type)<tr><td><strong>{{ $type->code }}</strong></td><td>{{ $type->name }}</td><td>{{ $type->prefix }}</td><td>{{ $type->year_format }}</td><td>{{ $type->padding }}</td><td>{{ str($type->reset_period)->headline() }}</td><td><span class="status-badge {{ $type->is_active ? 'success' : 'neutral' }}">{{ $type->is_active ? 'Active' : 'Inactive' }}</span></td><td>{{ $type->journals_count }}</td><td>@can('voucher_types.manage')<a class="btn btn-outline-primary btn-sm" href="{{ route('voucher-types.edit', $type) }}">Configure</a>@endcan</td></tr>@endforeach</tbody>
</table></div></div>
@endsection
