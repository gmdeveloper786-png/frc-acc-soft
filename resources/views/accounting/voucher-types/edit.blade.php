@extends('layouts.app')
@section('title', 'Configure Voucher Type')
@section('page-title', 'Configure '.$voucherType->code)
@section('page-description', 'Numbering rules become immutable after the first posted journal for this voucher type.')
@section('content')
<div class="surface-card"><form method="POST" action="{{ route('voucher-types.update', $voucherType) }}">@csrf @method('PUT')
    <div class="form-grid">
        <div><label class="form-label">Name</label><input class="form-control" name="name" value="{{ old('name', $voucherType->name) }}" required>@error('name')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div><label class="form-label">Prefix</label><input class="form-control" name="prefix" value="{{ old('prefix', $voucherType->prefix) }}" required>@error('prefix')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <div><label class="form-label">Year Format</label><select class="form-select" name="year_format">@foreach(['Y' => 'Four digits', 'y' => 'Two digits', 'none' => 'No year'] as $value => $label)<option value="{{ $value }}" @selected(old('year_format', $voucherType->year_format) === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label">Sequence Padding</label><input class="form-control" type="number" min="3" max="10" name="padding" value="{{ old('padding', $voucherType->padding) }}" required></div>
        <div><label class="form-label">Reset Period</label><select class="form-select" name="reset_period"><option value="yearly" @selected(old('reset_period', $voucherType->reset_period) === 'yearly')>Fiscal year</option><option value="global" @selected(old('reset_period', $voucherType->reset_period) === 'global')>Never / global</option></select></div>
        <div><label class="form-label">Status</label><select class="form-select" name="is_active"><option value="1" @selected(old('is_active', (int) $voucherType->is_active) == 1)>Active</option><option value="0" @selected(old('is_active', (int) $voucherType->is_active) == 0)>Inactive</option></select></div>
    </div>
    <div class="form-actions"><a class="btn btn-outline-primary" href="{{ route('voucher-types.index') }}">Cancel</a><button class="btn btn-primary">Save Configuration</button></div>
</form></div>
@endsection
