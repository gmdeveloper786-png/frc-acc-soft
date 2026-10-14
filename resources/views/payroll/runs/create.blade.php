@extends('layouts.app') @section('title','New Payroll Run') @section('eyebrow','Payroll') @section('page-title','New Payroll Run')
@section('page-description','Creates a draft from the salary structures of the active employees of one branch. Nothing is posted.')
@section('content')
@include('payroll._policy-banner')
<form method="POST" action="{{ route('payroll-runs.store') }}" data-submit-once>@csrf
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">One run per branch and period</span><h2>Run details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="branch_id">Branch</label><select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="accounting_period_id">Accounting period (open periods only)</label><select class="form-select @error('accounting_period_id') is-invalid @enderror" id="accounting_period_id" name="accounting_period_id" required><option value="">Select period</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(old('accounting_period_id')==$period->id)>{{ $period->name }} ({{ $period->start_date->format('d M Y') }} – {{ $period->end_date->format('d M Y') }})</option>@endforeach</select>@error('accounting_period_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    @if($periods->isEmpty())<div class="alert alert-warning mt-3 mb-0">No accounting period is open. Open a period before preparing payroll.</div>@endif
    <p class="card-copy">Each line starts with the amounts of the employee's salary structure and can be edited while the run is a draft. To start from the client's spreadsheet instead, use the payroll import.</p>
    <div class="form-actions"><button class="btn btn-primary">Create draft run</button><a class="btn btn-outline-primary" href="{{ route('payroll-runs.index') }}">Cancel</a></div>
</section>
</form>
@endsection
