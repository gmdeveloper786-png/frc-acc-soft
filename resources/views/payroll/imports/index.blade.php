@extends('layouts.app') @section('title','Payroll Import') @section('eyebrow','Payroll') @section('page-title','Payroll Import')
@section('page-description','Upload → Preview → Column mapping → Validation → Error report → Authorised confirmation → Draft payroll. Nothing is imported at upload and nothing is ever posted by an import.')
@section('content')
@include('payroll._policy-banner')
<form method="POST" action="{{ route('payroll-imports.store') }}" enctype="multipart/form-data" data-submit-once>@csrf
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Step 1 — stored privately, nothing imported</span><h2>Upload a payroll spreadsheet</h2></div></div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="branch_id">Branch</label><select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="accounting_period_id">Accounting period (open periods only)</label><select class="form-select @error('accounting_period_id') is-invalid @enderror" id="accounting_period_id" name="accounting_period_id" required><option value="">Select period</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(old('accounting_period_id')==$period->id)>{{ $period->name }}</option>@endforeach</select>@error('accounting_period_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="workbook">File (.xlsx, .xls or .csv, up to 5 MB)</label><input class="form-control @error('workbook') is-invalid @enderror" id="workbook" type="file" name="workbook" accept=".xlsx,.xls,.csv" required>@error('workbook')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    <p class="card-copy">Only the first worksheet is read. Spreadsheet formulas are never executed: a formula cell is imported with the result stored in the file and must be verified, and a cell using RAND, RANDBETWEEN, NOW, TODAY, OFFSET or INDIRECT is rejected. Every employee must already exist in the employee master of the selected branch.</p>
    <div class="form-actions"><button class="btn btn-primary">Upload for preview</button></div>
</section>
</form>

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Your branches</span><h2>Imports</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Import</th><th>File</th><th>Branch</th><th>Period</th><th>Uploaded by</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($imports as $import)
        <tr>
            <td><code>#{{ $import->id }}</code><span class="table-subtext">{{ $import->created_at->format('d M Y H:i') }}</span></td>
            <td>{{ $import->original_filename }}</td>
            <td>{{ $import->branch->name }}</td>
            <td>{{ $import->period->name }}</td>
            <td>{{ $import->creator?->name ?? '—' }}</td>
            <td><span class="status-badge {{ $import->status->badge() }}">{{ $import->status->label() }}</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ in_array($import->status->value,['validated','confirmed'],true) ? route('payroll-imports.report',$import) : route('payroll-imports.show',$import) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No imports yet" description="Upload a payroll spreadsheet to preview and map its columns." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $imports->links() }}
</section>
@endsection
