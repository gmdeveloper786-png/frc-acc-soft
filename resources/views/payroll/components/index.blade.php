@extends('layouts.app') @section('title','Payroll Components') @section('eyebrow','Payroll') @section('page-title','Payroll Components')
@section('page-description','Configurable earnings and deductions. No tax, leave or statutory rule is built in.')
@section('content')
<section class="surface-card mb-4">
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Category</th><th>Ledger</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($components as $component)
        <tr>
            <td><code>{{ $component->code }}</code></td>
            <td>{{ $component->name }}</td>
            <td>{{ $component->type->label() }}</td>
            <td>{{ $component->category->label() }}</td>
            <td>@if($component->account){{ $component->account->code }} — {{ $component->account->name }}@elseif($component->isEarning())<span class="table-subtext">Salary Expense mapping</span>@else<span class="status-badge pending">Ledger required before posting</span>@endif</td>
            <td><span class="status-badge {{ $component->is_active ? 'success' : 'neutral' }}">{{ $component->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-components.edit',$component) }}">Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No payroll components" description="Add the earnings and deductions your payroll uses." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<form method="POST" action="{{ route('payroll-components.store') }}" data-submit-once>@csrf
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Configuration</span><h2>Add a component</h2></div></div>
    @include('payroll.components._fields', ['component' => null])
    <div class="form-actions"><button class="btn btn-primary">Add component</button></div>
</section>
</form>
@endsection
