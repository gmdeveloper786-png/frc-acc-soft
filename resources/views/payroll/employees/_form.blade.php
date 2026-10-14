@php
    $record = $employee ?? null;
@endphp
<form method="POST" action="{{ $record ? route('employees.update',$record) : route('employees.store') }}" data-submit-once>@csrf @if($record) @method('PUT') @endif
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Employee master</span><h2>Employee details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="employee_code">Employee code</label><input class="form-control @error('employee_code') is-invalid @enderror" id="employee_code" name="employee_code" maxlength="40" value="{{ old('employee_code',$record?->employee_code) }}" required>@error('employee_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="255" value="{{ old('name',$record?->name) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="designation">Designation</label><input class="form-control @error('designation') is-invalid @enderror" id="designation" name="designation" maxlength="255" value="{{ old('designation',$record?->designation) }}">@error('designation')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="branch_id">Branch</label><select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id',$record?->branch_id)==$branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="employment_status">Employment status</label><select class="form-select @error('employment_status') is-invalid @enderror" id="employment_status" name="employment_status" required>@foreach(\App\Enums\EmploymentStatus::cases() as $status)<option value="{{ $status->value }}" @selected(old('employment_status',$record?->employment_status?->value ?? 'active')===$status->value)>{{ $status->label() }}</option>@endforeach</select>@error('employment_status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="joined_on">Joined on</label><input class="form-control @error('joined_on') is-invalid @enderror" id="joined_on" type="date" name="joined_on" value="{{ old('joined_on',$record?->joined_on?->toDateString()) }}">@error('joined_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @if($sensitive)
        <div class="col-md-6"><label class="form-label" for="bank_details">Bank details (stored encrypted)</label><textarea class="form-control @error('bank_details') is-invalid @enderror" id="bank_details" name="bank_details" rows="3" maxlength="2000" autocomplete="off">{{ old('bank_details',$bankDetails ?? null) }}</textarea>@error('bank_details')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @endif
        <div class="col-md-6"><label class="form-label" for="notes">Notes</label><textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes',$record?->notes) }}</textarea>@error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    @unless($sensitive)<p class="card-copy">Bank details and salary amounts are managed by users with the sensitive payroll permission; saving this form leaves them unchanged.</p>@endunless
    <div class="form-actions"><button class="btn btn-primary">{{ $record ? 'Save changes' : 'Create employee' }}</button><a class="btn btn-outline-primary" href="{{ $record ? route('employees.show',$record) : route('employees.index') }}">Cancel</a></div>
</section>
</form>
