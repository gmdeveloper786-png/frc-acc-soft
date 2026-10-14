@extends('layouts.app') @section('title','Payroll Import Report #'.$import->id) @section('eyebrow','Payroll import') @section('page-title','Validation and Error Report')
@section('page-description',$import->original_filename.' • '.$import->branch->name.' • '.$import->period->name.' • '.$import->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="{{ route('payroll-imports.export',$import) }}">Download report (CSV)</a>
    @if($import->status->isOpen())<a class="btn btn-outline-primary" href="{{ route('payroll-imports.show',$import) }}">Change mapping</a>@endif
    <a class="btn btn-outline-primary" href="{{ route('payroll-imports.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $open = $import->status->isOpen();
    $importErrors = $import->summary['import_errors'] ?? [];
    $blocked = $counts['error_rows'] > 0 || !empty($importErrors) || $counts['ready_rows'] === 0;
@endphp
@foreach(['status','rows','acknowledge_formulas','workbook','accounting_period_id','lines'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach
@foreach($importErrors as $message)<div class="alert alert-danger">{{ $message }}</div>@endforeach
@if($import->status === \App\Enums\PayrollImportStatus::Confirmed)
<div class="alert alert-success">Confirmed on {{ $import->confirmed_at?->format('d M Y H:i') }}. It created draft payroll run #{{ $import->payroll_run_id }}@can('payroll.view') — <a href="{{ route('payroll-runs.show',$import->payroll_run_id) }}">open the run</a>@endcan. The run still has to be validated, approved and posted.</div>
@endif

<div class="kpi-grid">
    <x-kpi-card label="Rows read" value="{{ $counts['total_rows'] }}" icon="R" tone="navy" status="Step 4" description="{{ $counts['excluded_rows'] }} explicitly excluded"/>
    <x-kpi-card label="Rows with errors" value="{{ $counts['error_rows'] }}" icon="!" tone="rose" status="{{ $counts['error_rows'] > 0 ? 'Blocks confirmation' : 'None' }}" description="Correct the file or exclude the rows"/>
    <x-kpi-card label="Rows with warnings" value="{{ $counts['warning_rows'] }}" icon="W" tone="amber" status="Review" description="{{ $counts['formula_warning_rows'] }} rows computed by spreadsheet formulas"/>
    <x-kpi-card label="Ready to import" value="{{ $counts['ready_rows'] }}" icon="✓" tone="teal" status="Draft only" description="Included rows without errors"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Step 5 — every row, with its errors and warnings</span><h2>Rows</h2></div></div>
    <form class="filter-bar" method="GET">
        <select class="form-select" name="show"><option value="">All rows</option><option value="errors" @selected(request('show')==='errors')>Rows with errors</option><option value="warnings" @selected(request('show')==='warnings')>Rows with warnings</option><option value="excluded" @selected(request('show')==='excluded')>Excluded rows</option></select>
        <button class="btn btn-primary">Filter</button>
    </form>
    @if($open)<form method="POST" action="{{ route('payroll-imports.exclude',$import) }}" id="rowsForm" data-submit-once>@csrf</form>@endif
    <div class="table-responsive"><table class="table accounting-table"><thead><tr>@if($open)<th></th>@endif<th>Row</th><th>Employee</th>
        @if($sensitive) @foreach($components as $component)<th class="text-end">{{ $component->name }}</th>@endforeach <th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th>@endif
        <th>Result</th></tr></thead><tbody>
    @forelse($rows as $row)
        @php
            $values = $sensitive ? ($row->mapped_values ?? []) : [];
        @endphp
        <tr>
            @if($open)<td><input class="form-check-input" type="checkbox" name="rows[]" value="{{ $row->id }}" form="rowsForm" aria-label="Select row {{ $row->row_number }}"></td>@endif
            <td>{{ $row->row_number }}</td>
            <td>@if($row->employee)<code>{{ $row->employee->employee_code }}</code> {{ $row->employee->name }}@else<span class="table-subtext">Not matched to an employee</span>@endif</td>
            @if($sensitive)
                @foreach($components as $component)<td class="text-end font-monospace">{{ isset($values['amounts'][$component->id]) ? number_format((float)$values['amounts'][$component->id],2) : '—' }}</td>@endforeach
                <td class="text-end font-monospace">{{ isset($values['gross']) ? number_format((float)$values['gross'],2) : '—' }}</td>
                <td class="text-end font-monospace">{{ isset($values['deductions']) ? number_format((float)$values['deductions'],2) : '—' }}</td>
                <td class="text-end font-monospace">{{ isset($values['net']) ? number_format((float)$values['net'],2) : '—' }}</td>
            @endif
            <td>
                @if($row->is_excluded)<span class="status-badge neutral d-block mb-1">Excluded</span>@endif
                @foreach($row->errors ?? [] as $error)<span class="status-badge danger d-block mb-1">{{ $error }}</span>@endforeach
                @foreach($row->warnings ?? [] as $warning)<span class="status-badge pending d-block mb-1">{{ $warning }}</span>@endforeach
                @if(empty($row->errors) && empty($row->warnings) && !$row->is_excluded)<span class="status-badge success">OK</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="{{ ($sensitive ? $components->count() + 3 : 0) + ($open ? 4 : 3) }}"><x-empty-state title="No rows" description="No rows match this filter." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $rows->links() }}
    @if($open)
    <div class="form-actions">
        <button class="btn btn-outline-danger" form="rowsForm" name="action" value="exclude" data-confirm="Exclude the selected rows from this import? Excluded employees will not be on the draft payroll run.">Exclude selected rows</button>
        <button class="btn btn-outline-primary" form="rowsForm" name="action" value="include">Return selected rows to the import</button>
    </div>
    @endif
</section>

@if($open)
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Step 6 — creates a draft payroll run only</span><h2>Authorised confirmation</h2></div></div>
    @if($blocked)
        <div class="alert alert-warning mb-3">Confirmation is not possible yet: @if($counts['error_rows'] > 0){{ $counts['error_rows'] }} included row(s) have errors. @endif @if(!empty($importErrors))The import has a blocking problem shown above. @endif @if($counts['ready_rows'] === 0)No row is ready to import. @endif</div>
    @endif
    <form method="POST" action="{{ route('payroll-imports.confirm',$import) }}" data-submit-once>@csrf
        @if($counts['formula_warning_rows'] > 0)
        <div class="form-check mb-3"><input type="hidden" name="acknowledge_formulas" value="0"><input class="form-check-input" type="checkbox" id="acknowledge_formulas" name="acknowledge_formulas" value="1"><label class="form-check-label" for="acknowledge_formulas">I have verified the {{ $counts['formula_warning_rows'] }} row(s) whose amounts were computed by spreadsheet formulas. The values stored in the file are the amounts to import.</label></div>
        @endif
        <p class="card-copy">Confirming creates one <strong>draft</strong> payroll run for {{ $import->branch->name }}, {{ $import->period->name }}, with {{ $counts['ready_rows'] }} employee line(s). It is not validated, approved or posted, and no employee is created or changed.</p>
        <div class="form-actions">
            <button class="btn btn-primary" @disabled($blocked) data-confirm="Create a draft payroll run from this import?">Confirm and create draft payroll</button>
        </div>
    </form>
    <form class="mt-3" method="POST" action="{{ route('payroll-imports.cancel',$import) }}" data-submit-once>@csrf<button class="btn btn-outline-danger" data-confirm="Cancel this import and delete the stored file?">Cancel import</button></form>
</section>
@endif
@endsection
