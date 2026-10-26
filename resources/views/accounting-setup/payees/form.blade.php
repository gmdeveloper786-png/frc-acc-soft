@extends('layouts.app')
@section('title', $payee->exists ? 'Edit Payee' : 'Add Payee')
@section('eyebrow', 'Payee Directory')
@section('page-title', $payee->exists ? 'Edit '.$payee->name : 'Add Payee')

@php($locked = $locked ?? false)
@section('content')
<form class="surface-card" method="POST" action="{{ $payee->exists ? route('payees.update', $payee) : route('payees.store') }}">
    @csrf
    @if($payee->exists) @method('PUT') @endif
    <div class="form-stack">
        @if($locked)<div class="alert alert-info">Payments already name this payee, so its payee type is fixed. Past payments keep the name they were saved with.</div>@endif
        @if($types->isEmpty())<div class="alert alert-warning">No active payee type uses the Payee Directory. Add one under Payee Types first.</div>@endif
        <div class="form-grid">
            <div>
                <label class="form-label" for="payee-name">Payee name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="payee-name" name="name" value="{{ old('name', $payee->name) }}" maxlength="255" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="payee-type">Payee type</label>
                @if($locked)<input type="hidden" name="payee_type_id" value="{{ $payee->payee_type_id }}">@endif
                <select class="form-select @error('payee_type_id') is-invalid @enderror" id="payee-type" @if($locked) disabled @else name="payee_type_id" @endif required>
                    <option value="">Select payee type</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}" @selected((string) old('payee_type_id', $payee->payee_type_id) === (string) $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('payee_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="payee-kind">Individual / organization</label>
                <select class="form-select" id="payee-kind" name="entity_kind" required>
                    @foreach(\App\Models\Payee::ENTITY_KINDS as $value => $label)
                        <option value="{{ $value }}" @selected(old('entity_kind', $payee->entity_kind) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="payee-reference">Reference (optional)</label>
                <input class="form-control" id="payee-reference" name="reference" value="{{ old('reference', $payee->reference) }}" maxlength="100">
            </div>
            <div>
                <label class="form-label" for="payee-phone">Phone (optional)</label>
                <input class="form-control" id="payee-phone" name="phone" value="{{ old('phone', $payee->phone) }}" maxlength="50">
            </div>
            <div>
                <label class="form-label" for="payee-email">Email (optional)</label>
                <input class="form-control @error('email') is-invalid @enderror" type="email" id="payee-email" name="email" value="{{ old('email', $payee->email) }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-span-2">
                <label class="form-label" for="payee-address">Address (optional)</label>
                <textarea class="form-control" id="payee-address" name="address" rows="2" maxlength="2000">{{ old('address', $payee->address) }}</textarea>
            </div>
            <div class="form-span-2">
                <label class="form-label" for="payee-description">Description (optional)</label>
                <textarea class="form-control" id="payee-description" name="description" rows="2" maxlength="2000">{{ old('description', $payee->description) }}</textarea>
            </div>
            <div>
                <input type="hidden" name="is_active" value="0">
                <label class="choice-card"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((string) old('is_active', $payee->is_active ? '1' : '0') === '1')><span><strong>Active</strong><small>Inactive payees are not offered on Create Payment; their past payments stay unchanged.</small></span></label>
            </div>
        </div>
        <div class="form-actions"><a class="btn btn-outline-primary" href="{{ route('payees.index') }}">Cancel</a><button class="btn btn-primary">Save payee</button></div>
    </div>
</form>
@endsection
