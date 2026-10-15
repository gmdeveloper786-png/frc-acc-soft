@extends('layouts.app') @section('title','Staff Advances') @section('eyebrow','Payroll') @section('page-title','Staff Advances and Loans')
@section('page-description','Register and recovery schedule: Draft → Submitted → Approved → Active → Settled. Paid out through the payment workflow; recovered through posted payroll runs.')
@section('page-actions')
<div class="d-flex gap-2">
    @if($sensitive && auth()->user()->can('payroll.view'))<a class="btn btn-outline-primary" href="{{ route('payroll-reports.show','advances-outstanding') }}">Outstanding report</a>@endif
    @if($sensitive)@can('staff_advances.manage')<a class="btn btn-primary" href="{{ route('staff-advances.create') }}">+ New advance</a>@endcan @endif
</div>
@endsection
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <select class="form-select" name="branch_id" aria-label="Branch"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="employee_id" aria-label="Employee"><option value="">All employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected(request('employee_id')==$employee->id)>{{ $employee->employee_code }} — {{ $employee->name }}</option>@endforeach</select>
        <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach(\App\Enums\StaffAdvanceStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('staff-advances.index') }}">Reset</a>
    </form>
    @unless($sensitive)<div class="alert alert-warning mt-3" role="alert">Amounts are hidden: your account does not hold the sensitive payroll permission.</div>@endunless
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Reference</th><th>Employee</th><th>Branch</th><th>Type</th><th>First recovery</th><th>Status</th>@if($sensitive)<th class="text-end">Principal</th><th class="text-end">Recovered</th><th class="text-end">Outstanding</th>@endif<th></th></tr></thead><tbody>
    @forelse($advances as $advance)
        <tr>
            <td><code>{{ $advance->reference }}</code></td>
            <td>{{ $advance->employee->name }}<span class="table-subtext">{{ $advance->employee->employee_code }}</span></td>
            <td>{{ $advance->branch->name }}</td>
            <td>{{ $advance->type->label() }}</td>
            <td>{{ $advance->firstRecoveryPeriod->name }}</td>
            <td><span class="status-badge {{ $advance->status->badge() }}">{{ $advance->status->label() }}</span></td>
            @if($sensitive)
            <td class="text-end font-monospace">{{ number_format((float) $advance->principal_amount, 2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float) $advance->recoveredAmount(), 2) }}</td>
            <td class="text-end font-monospace">{{ $advance->status === \App\Enums\StaffAdvanceStatus::Cancelled ? '—' : number_format((float) $advance->outstandingAmount(), 2) }}</td>
            @endif
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('staff-advances.show',$advance) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="{{ $sensitive ? 10 : 7 }}"><x-empty-state title="No staff advances found" description="Record an advance or loan, submit it for approval, pay it out through the payment workflow and link the posted payment." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $advances->links() }}
</section>
@endsection
