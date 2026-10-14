@extends('layouts.app') @section('title', 'Application Settings') @section('eyebrow', 'Configuration') @section('page-title', 'Application Settings') @section('page-description', 'Manage business values centrally without exposing integration credentials.')
@section('content')
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">@csrf @method('PUT')
@foreach($groups as $groupKey => $groupLabel)
    @continue(empty($fields->get($groupKey)))
    <section class="surface-card settings-section"><div class="card-heading"><h2>{{ $groupLabel }}</h2></div><div class="form-grid">
    @foreach($fields->get($groupKey, []) as $key => $field)
        @php($inputKey = str_replace('.', '__', $key))
        <div class="{{ $field['type'] === 'textarea' ? 'form-span-2' : '' }}"><label class="form-label" for="{{ $inputKey }}">{{ $field['label'] }}</label>
        @if($field['type'] === 'select')<select class="form-select @error('settings.'.$inputKey) is-invalid @enderror" id="{{ $inputKey }}" name="settings[{{ $inputKey }}]">@foreach($field['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) old('settings.'.$inputKey, $values[$key]) === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach</select>
        @elseif($field['type'] === 'textarea')<textarea class="form-control @error('settings.'.$inputKey) is-invalid @enderror" id="{{ $inputKey }}" name="settings[{{ $inputKey }}]" rows="3">{{ old('settings.'.$inputKey, $values[$key]) }}</textarea>
        @elseif($field['type'] === 'file')<input class="form-control @error('settings.'.$inputKey) is-invalid @enderror" id="{{ $inputKey }}" type="file" name="settings[{{ $inputKey }}]" accept=".png,.jpg,.jpeg,.webp"><small class="form-text">Current: {{ $values[$key] ?: 'No logo configured' }}. Maximum 2 MB.</small>
        @else<input class="form-control @error('settings.'.$inputKey) is-invalid @enderror" id="{{ $inputKey }}" type="{{ $field['type'] }}" name="settings[{{ $inputKey }}]" value="{{ old('settings.'.$inputKey, $values[$key]) }}" @if(isset($field['step'])) step="{{ $field['step'] }}" @endif>@endif
        @error('settings.'.$inputKey)<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    @endforeach
    </div></section>
@endforeach
@can('settings.update')<div class="sticky-actions"><button class="btn btn-primary" type="submit">Save Settings</button></div>@endcan
</form>
<section class="surface-card settings-section"><div class="card-heading"><div><span class="section-kicker">Read-only here</span><h2>Approval controls</h2></div>@if($canManageApprovals)<a class="btn btn-outline-primary" href="{{ route('approval-settings.edit') }}">Change approval controls</a>@endif</div>
    <p class="card-copy">Approval thresholds and policies decide who may authorize spending. Only a Director can change them, and every change is audited with its reason.</p>
    <dl class="definition-list">@foreach($approvalControls as $key => $field)<dt>{{ $field['label'] }}</dt><dd>{{ $field['options'][$values[$key]] ?? $values[$key] }}</dd>@endforeach</dl>
</section>
@endsection
