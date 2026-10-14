@extends('layouts.app') @section('title', 'Approval Controls') @section('eyebrow', 'Director authority') @section('page-title', 'Approval Controls')
@section('page-description', 'Thresholds and policies that decide who may authorize spending. Every change is audited with its old value, new value and reason.')
@section('content')
<form method="POST" action="{{ route('approval-settings.update') }}" data-submit-once>@csrf @method('PUT')
<section class="surface-card settings-section"><div class="card-heading"><h2>Approval thresholds and policies</h2></div>
    <div class="alert alert-warning">A change takes effect immediately for every record still awaiting approval.</div>
    <div class="form-grid">
    @foreach($fields as $key => $field)
        @php($inputKey = str_replace('.', '__', $key))
        <div><label class="form-label" for="{{ $inputKey }}">{{ $field['label'] }}</label>
        @if($field['type'] === 'select')<select class="form-select @error($inputKey) is-invalid @enderror" id="{{ $inputKey }}" name="settings[{{ $inputKey }}]">@foreach($field['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) old('settings.'.$inputKey, $values[$key]) === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach</select>
        @else<input class="form-control @error($inputKey) is-invalid @enderror" id="{{ $inputKey }}" type="{{ $field['type'] }}" name="settings[{{ $inputKey }}]" value="{{ old('settings.'.$inputKey, $values[$key]) }}" @if(isset($field['step'])) step="{{ $field['step'] }}" @endif>@endif
        @error($inputKey)<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    @endforeach
        <div class="form-span-2"><label class="form-label" for="reason">Reason for this change</label><textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason" rows="2" minlength="10" maxlength="1000" required placeholder="Owner or board approval reference">{{ old('reason') }}</textarea>@error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
</section>
<div class="sticky-actions"><button class="btn btn-primary" type="submit" data-confirm="Change the approval controls? This is audited and takes effect immediately.">Save approval controls</button></div>
</form>
@endsection
