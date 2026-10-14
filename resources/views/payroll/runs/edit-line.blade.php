@extends('layouts.app') @section('title','Edit Payroll Line') @section('eyebrow','Payroll') @section('page-title',$line->employee->name)
@section('page-description',$line->employee->employee_code.' • '.$run->period->name.' • '.$run->branch->name)
@section('content')
@php
    $current = $line->items->keyBy('payroll_component_id');
@endphp
<form method="POST" action="{{ route('payroll-runs.lines.update',[$run,$line]) }}" data-submit-once>@csrf @method('PUT')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Draft — exact 4-decimal amounts</span><h2>Line amounts</h2></div></div>
    @if($errors->has('amounts') || $errors->has('amounts.*'))<div class="alert alert-danger">@foreach(collect($errors->get('amounts'))->merge(collect($errors->get('amounts.*'))->flatten())->unique() as $message)<span class="d-block">{{ $message }}</span>@endforeach</div>@endif
    @if(!empty($line->validation_errors))<div class="alert alert-warning">@foreach($line->validation_errors as $error)<span class="d-block">{{ $error }}</span>@endforeach</div>@endif
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Component</th><th>Type</th><th>Category</th><th class="text-end">Amount</th></tr></thead><tbody>
    @foreach($components as $component)
        <tr>
            <td>{{ $component->name }} @unless($component->is_active)<span class="status-badge neutral">Inactive</span>@endunless</td>
            <td>{{ $component->type->label() }}</td>
            <td>{{ $component->category->label() }}</td>
            <td class="text-end"><input class="form-control text-end" style="max-width:14rem;margin-left:auto" name="amounts[{{ $component->id }}]" inputmode="decimal" value="{{ old('amounts.'.$component->id, $current->get($component->id)?->amount) }}" aria-label="{{ $component->name }} amount"></td>
        </tr>
    @endforeach
    </tbody></table></div>
    <p class="card-copy">Gross, deductions and net are recalculated exactly on save: gross is the sum of earnings and net is gross less deductions. Tax, leave and statutory amounts are whatever you enter here; nothing is computed for you.</p>
    <div class="form-actions"><button class="btn btn-primary">Save line</button><a class="btn btn-outline-primary" href="{{ route('payroll-runs.show',$run) }}">Cancel</a></div>
</section>
</form>
@endsection
