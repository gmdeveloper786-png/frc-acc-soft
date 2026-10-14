@extends('layouts.app') @section('title','Payroll Import #'.$import->id) @section('eyebrow','Payroll import') @section('page-title','Preview and Column Mapping')
@section('page-description',$import->original_filename.' • '.$import->branch->name.' • '.$import->period->name.' • '.$import->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @if(in_array($import->status->value,['validated','confirmed'],true))<a class="btn btn-outline-primary" href="{{ route('payroll-imports.report',$import) }}">Validation report</a>@endif
    <a class="btn btn-outline-primary" href="{{ route('payroll-imports.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $headers = $import->detected_headers ?? [];
    $open = $import->status->isOpen();
    $columnOptions = function (?string $selected) use ($headers): string {
        $html = '<option value="">— not mapped —</option>';
        foreach ($headers as $letter => $label) {
            $html .= '<option value="'.e($letter).'"'.($selected === $letter ? ' selected' : '').'>'.e($letter.' — '.$label).'</option>';
        }

        return $html;
    };
@endphp
@foreach(['status','workbook','components','employee_code','employee_name','stated_gross','stated_net','pay_date'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Step 2 — as stored in the file; formulas are not executed</span><h2>Preview</h2></div><span class="status-badge {{ $import->status->badge() }}">{{ $import->status->label() }}</span></div>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><small>Worksheet</small><strong class="d-block">{{ $import->sheet_name ?: '—' }}</strong></div>
        <div class="col-md-3"><small>Detected header row</small><strong class="d-block">Row {{ $import->header_row }}</strong></div>
        <div class="col-md-3"><small>First data row</small><strong class="d-block">Row {{ $import->data_start_row }}</strong></div>
        <div class="col-md-3"><small>Data rows</small><strong class="d-block">{{ $import->summary['data_rows'] ?? $import->summary['total_rows'] ?? '—' }}</strong></div>
    </div>
    <div class="table-responsive"><table class="table accounting-table"><thead>
        <tr><th>Row</th>@foreach($headers as $letter => $label)<th>{{ $letter }}<span class="table-subtext">{{ $label }}</span></th>@endforeach</tr></thead><tbody>
    @forelse($preview as $number => $cells)
        <tr><td>{{ $number }}</td>@foreach($headers as $letter => $label)
            @php
                $cell = $cells[$letter] ?? null;
            @endphp
            <td class="font-monospace">@if($cell === null) @elseif($cell['unsafe'])<span class="status-badge danger">Volatile formula — not read</span>@else{{ $cell['value'] ?? '' }}@if($cell['formula']) <span class="status-badge pending">formula</span>@endif @endif</td>
        @endforeach</tr>
    @empty
        <tr><td colspan="{{ count($headers) + 1 }}"><x-empty-state title="{{ $open ? 'Cell values hidden' : 'Preview closed' }}" description="{{ $open ? 'Spreadsheet values are salary data and are shown only to users with the sensitive payroll permission. The column headings above are enough to map the columns.' : 'This import is '.$import->status->value.'.' }}" /></td></tr>
    @endforelse
    </tbody></table></div>
    @if($preview)<p class="card-copy">Showing the first {{ count($preview) }} data rows. Cells marked “formula” show the result stored in the file and will need to be verified.</p>@endif
</section>

@if($open)
<form method="POST" action="{{ route('payroll-imports.mapping',$import) }}" data-submit-once>@csrf
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Step 3 — suggestions are based on the headings and must be checked</span><h2>Column mapping</h2></div></div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="map_employee_code">Employee code (required)</label><select class="form-select" id="map_employee_code" name="employee_code" required>{!! $columnOptions(old('employee_code', $mapping['employee_code'] ?? null)) !!}</select></div>
        <div class="col-md-4"><label class="form-label" for="map_employee_name">Employee name (cross-check only)</label><select class="form-select" id="map_employee_name" name="employee_name">{!! $columnOptions(old('employee_name', $mapping['employee_name'] ?? null)) !!}</select></div>
        <div class="col-md-4"><label class="form-label" for="map_pay_date">Date column (validated only)</label><select class="form-select" id="map_pay_date" name="pay_date">{!! $columnOptions(old('pay_date', $mapping['pay_date'] ?? null)) !!}</select></div>
        <div class="col-md-6"><label class="form-label" for="map_stated_gross">Gross stated in the file (consistency check only)</label><select class="form-select" id="map_stated_gross" name="stated_gross">{!! $columnOptions(old('stated_gross', $mapping['stated_gross'] ?? null)) !!}</select></div>
        <div class="col-md-6"><label class="form-label" for="map_stated_net">Net stated in the file (consistency check only)</label><select class="form-select" id="map_stated_net" name="stated_net">{!! $columnOptions(old('stated_net', $mapping['stated_net'] ?? null)) !!}</select></div>
    </div>
    <div class="table-responsive mt-4"><table class="table accounting-table"><thead><tr><th>Payroll component</th><th>Type</th><th>Category</th><th>Spreadsheet column</th><th>Value required in every row</th></tr></thead><tbody>
    @forelse($components as $component)
        <tr>
            <td>{{ $component->name }}</td>
            <td>{{ $component->type->label() }}</td>
            <td>{{ $component->category->label() }}</td>
            <td><select class="form-select" name="components[{{ $component->id }}]" aria-label="Column for {{ $component->name }}">{!! $columnOptions(old('components.'.$component->id, $mapping['components'][$component->id] ?? null)) !!}</select></td>
            <td><input class="form-check-input" type="checkbox" name="required[]" value="{{ $component->id }}" @checked(in_array($component->id, array_map('intval', old('required', $mapping['required'] ?? [])), true)) aria-label="{{ $component->name }} required"></td>
        </tr>
    @empty
        <tr><td colspan="5"><x-empty-state title="No active payroll components" description="Create payroll components before mapping columns." /></td></tr>
    @endforelse
    </tbody></table></div>
    <p class="card-copy">Map only columns that hold real, fixed amounts. Totals and stated gross/net columns are never imported as amounts — they are compared with the gross and net computed from the mapped components. Columns that do not exist in the file are not invented.</p>
    <div class="form-actions">
        <button class="btn btn-primary">Save mapping and validate rows</button>
    </div>
</section>
</form>
<form method="POST" action="{{ route('payroll-imports.cancel',$import) }}" data-submit-once>@csrf<button class="btn btn-outline-danger" data-confirm="Cancel this import and delete the stored file?">Cancel import</button></form>
@elseif($mapping)
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Closed</span><h2>Column mapping used</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Field</th><th>Column</th></tr></thead><tbody>
        @foreach(['employee_code' => 'Employee code', 'employee_name' => 'Employee name', 'stated_gross' => 'Stated gross', 'stated_net' => 'Stated net', 'pay_date' => 'Date'] as $field => $label)
            @if(!empty($mapping[$field]))<tr><td>{{ $label }}</td><td>{{ $mapping[$field] }} — {{ $headers[$mapping[$field]] ?? '' }}</td></tr>@endif
        @endforeach
        @foreach($components as $component)
            @if(!empty($mapping['components'][$component->id]))<tr><td>{{ $component->name }}</td><td>{{ $mapping['components'][$component->id] }} — {{ $headers[$mapping['components'][$component->id]] ?? '' }}</td></tr>@endif
        @endforeach
    </tbody></table></div>
</section>
@endif
@endsection
