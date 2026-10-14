@extends('layouts.app') @section('title','Employees') @section('eyebrow','Payroll') @section('page-title','Employees')
@section('page-description','Employee directory of your branches. Salary amounts and bank details are shown only on the employee record, to users with the sensitive payroll permission.')
@section('page-actions')@can('employees.manage')<a class="btn btn-primary" href="{{ route('employees.create') }}">+ New employee</a>@endcan @endsection
@section('content')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Code, name or designation">
        <select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Enums\EmploymentStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('employees.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Code</th><th>Name</th><th>Designation</th><th>Branch</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($employees as $employee)
        <tr>
            <td><code>{{ $employee->employee_code }}</code></td>
            <td>{{ $employee->name }}</td>
            <td>{{ $employee->designation ?: '—' }}</td>
            <td>{{ $employee->branch->name }}</td>
            <td>{{ $employee->joined_on?->format('d M Y') ?? '—' }}</td>
            <td><span class="status-badge {{ $employee->employment_status->badge() }}">{{ $employee->employment_status->label() }}</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('employees.show',$employee) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No employees found" description="Add an employee or change the filters. Employees are never created by a payroll import." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $employees->links() }}
</section>
@endsection
