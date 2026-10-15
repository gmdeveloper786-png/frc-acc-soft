@extends('layouts.app') @section('title','Employee '.$employee->employee_code) @section('eyebrow','Payroll') @section('page-title',$employee->name)
@section('page-description',$employee->employee_code.' • '.$employee->branch->name.' • '.$employee->employment_status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @can('employees.manage')<a class="btn btn-outline-primary" href="{{ route('employees.edit',$employee) }}">Edit</a>@endcan
    <a class="btn btn-outline-primary" href="{{ route('employees.index') }}">Back</a>
</div>
@endsection
@section('content')
@include('payroll._policy-banner')
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Employee master</span><h2>Details</h2></div><span class="status-badge {{ $employee->employment_status->badge() }}">{{ $employee->employment_status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>Employee code</small><strong class="d-block">{{ $employee->employee_code }}</strong></div>
        <div class="col-md-3"><small>Designation</small><strong class="d-block">{{ $employee->designation ?: '—' }}</strong></div>
        <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $employee->branch->name }}</strong></div>
        <div class="col-md-3"><small>Joined on</small><strong class="d-block">{{ $employee->joined_on?->format('d M Y') ?? '—' }}</strong></div>
        <div class="col-md-6"><small>Notes</small><p class="mb-0">{{ $employee->notes ?: '—' }}</p></div>
        @if($sensitive)<div class="col-md-6"><small>Bank details (sensitive)</small><p class="mb-0" style="white-space:pre-line">{{ $bankDetails ?: '—' }}</p></div>@endif
    </div>
</section>

@if($sensitive)
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Sensitive — amounts entered by an authorised user</span><h2>Salary structure</h2></div></div>
    @php
        $current = $employee->salaryItems->keyBy('payroll_component_id');
        $zero = \Brick\Math\BigDecimal::zero()->toScale(4);
        $gross = $employee->salaryItems->filter(fn ($item) => $item->component->isEarning())->reduce(fn ($sum, $item) => $sum->plus(\App\Services\AccountingDecimal::of($item->amount)), $zero);
        $deductions = $employee->salaryItems->reject(fn ($item) => $item->component->isEarning())->reduce(fn ($sum, $item) => $sum->plus(\App\Services\AccountingDecimal::of($item->amount)), $zero);
        $canManage = auth()->user()->can('employees.manage');
    @endphp
    @if($canManage)
    <form method="POST" action="{{ route('employees.salary.update',$employee) }}" data-submit-once>@csrf @method('PUT')
    @endif
    @if($errors->has('amounts') || $errors->has('amounts.*'))<div class="alert alert-danger">@foreach(collect($errors->get('amounts'))->merge(collect($errors->get('amounts.*'))->flatten())->unique() as $message)<span class="d-block">{{ $message }}</span>@endforeach</div>@endif
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Component</th><th>Type</th><th>Category</th><th class="text-end">Amount per period</th></tr></thead><tbody>
    @forelse($components as $component)
        <tr>
            <td>{{ $component->name }} @unless($component->is_active)<span class="status-badge neutral">Inactive</span>@endunless</td>
            <td>{{ $component->type->label() }}</td>
            <td>{{ $component->category->label() }}</td>
            <td class="text-end font-monospace">
                @if($canManage)
                    <input class="form-control text-end" style="max-width:14rem;margin-left:auto" name="amounts[{{ $component->id }}]" inputmode="decimal" value="{{ old('amounts.'.$component->id, $current->get($component->id)?->amount) }}" aria-label="{{ $component->name }} amount">
                @else
                    {{ $current->has($component->id) ? number_format((float)$current->get($component->id)->amount,2) : '—' }}
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="4"><x-empty-state title="No payroll components" description="Create payroll components before entering a salary structure." /></td></tr>
    @endforelse
    </tbody>
    @if($employee->salaryItems->isNotEmpty())
    <tfoot>
        <tr><th colspan="3" class="text-end">Gross (sum of earnings)</th><th class="text-end font-monospace">{{ number_format((float)(string)$gross,2) }}</th></tr>
        <tr><th colspan="3" class="text-end">Deductions</th><th class="text-end font-monospace">{{ number_format((float)(string)$deductions,2) }}</th></tr>
        <tr><th colspan="3" class="text-end">Net</th><th class="text-end font-monospace">{{ number_format((float)(string)$gross->minus($deductions),2) }}</th></tr>
    </tfoot>
    @endif
    </table></div>
    <p class="card-copy">Leave a field blank or zero to remove the component. Tax, leave and statutory amounts are entered here or imported; the system applies no rate or formula.</p>
    @if($canManage)
    <div class="form-actions"><button class="btn btn-primary" data-confirm="Save this salary structure? It is used the next time a draft payroll run is created.">Save salary structure</button></div>
    </form>
    @endif
</section>
@else
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Restricted</span><h2>Salary structure and bank details</h2></div></div>
    <x-empty-state title="Not available to your role" description="Salary amounts, salary structures and bank details are visible only to users with the sensitive payroll permission." />
</section>
@endif

@if($advances !== null)
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Sensitive — not yet settled or cancelled</span><h2>Outstanding staff advances</h2></div>@can('staff_advances.manage')<a class="btn btn-sm btn-outline-primary" href="{{ route('staff-advances.create') }}">+ New advance</a>@endcan</div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Reference</th><th>Type</th><th>First recovery</th><th>Status</th><th class="text-end">Principal</th><th class="text-end">Outstanding</th><th></th></tr></thead><tbody>
    @forelse($advances as $advance)
        <tr>
            <td><code>{{ $advance->reference }}</code></td>
            <td>{{ $advance->type->label() }}</td>
            <td>{{ $advance->firstRecoveryPeriod->name }}</td>
            <td><span class="status-badge {{ $advance->status->badge() }}">{{ $advance->status->label() }}</span></td>
            <td class="text-end font-monospace">{{ number_format((float)$advance->principal_amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$advance->outstandingAmount(),2) }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('staff-advances.show',$advance) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No outstanding advances" description="This employee has no advance or loan that is still being recovered." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endif

@can('payroll.view')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Most recent 24 runs</span><h2>Payroll history</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Period</th><th>Run status</th>@if($sensitive)<th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th>@endif<th></th></tr></thead><tbody>
    @forelse($history as $line)
        <tr>
            <td>{{ $line->run->period->name }}</td>
            <td><span class="status-badge {{ $line->run->status->badge() }}">{{ $line->run->status->label() }}</span></td>
            @if($sensitive)
            <td class="text-end font-monospace">{{ number_format((float)$line->gross_amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line->total_deductions,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line->net_amount,2) }}</td>
            @endif
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-runs.show',$line->payroll_run_id) }}">Run</a></td>
        </tr>
    @empty
        <tr><td colspan="{{ $sensitive ? 6 : 3 }}"><x-empty-state title="No payroll history" description="This employee has not been included in a payroll run yet." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endcan
@endsection
