@extends('layouts.app')
@section('title', $type->exists ? 'Edit Payee Type' : 'Add Payee Type')
@section('eyebrow', 'Payee Types')
@section('page-title', $type->exists ? 'Edit '.$type->name : 'Add Payee Type')

@php($locked = $locked ?? false)
@section('content')
<form class="surface-card" method="POST" action="{{ $type->exists ? route('payee-types.update', $type) : route('payee-types.store') }}">
    @csrf
    @if($type->exists) @method('PUT') @endif
    <div class="form-stack">
        @if($locked)<div class="alert alert-info">Payees or payments already use this type. Its code and source are fixed; the name, description, order and status can change.</div>@endif
        <div class="form-grid">
            <div>
                <label class="form-label" for="type-name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="type-name" name="name" value="{{ old('name', $type->name) }}" maxlength="100" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="type-code">Code</label>
                <input class="form-control @error('code') is-invalid @enderror" id="type-code" name="code" value="{{ old('code', $type->code) }}" maxlength="50" required @readonly($locked)>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="type-source">Payees come from</label>
                @if($locked)<input type="hidden" name="source_kind" value="{{ $type->source_kind }}">@endif
                <select class="form-select @error('source_kind') is-invalid @enderror" id="type-source" @if($locked) disabled @else name="source_kind" @endif required>
                    @foreach(\App\Models\PayeeType::SOURCES as $value => $label)
                        <option value="{{ $value }}" @selected(old('source_kind', $type->source_kind) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('source_kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text">Use the Payee Directory for any new category (landlords, consultants, authorities...). Vendors and employees are never copied.</small>
            </div>
            <div>
                <label class="form-label" for="type-order">Display order</label>
                <input class="form-control" type="number" min="0" id="type-order" name="sort_order" value="{{ old('sort_order', $type->sort_order ?? 0) }}">
            </div>
            <div class="form-span-2">
                <label class="form-label" for="type-description">Description (optional)</label>
                <textarea class="form-control" id="type-description" name="description" rows="2" maxlength="2000">{{ old('description', $type->description) }}</textarea>
            </div>
            <div>
                <input type="hidden" name="is_active" value="0">
                <label class="choice-card"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((string) old('is_active', $type->is_active ? '1' : '0') === '1')><span><strong>Active</strong><small>Inactive types are not offered on Create Payment.</small></span></label>
            </div>
        </div>
        <div class="form-actions"><a class="btn btn-outline-primary" href="{{ route('payee-types.index') }}">Cancel</a><button class="btn btn-primary">Save payee type</button></div>
    </div>
</form>
@endsection
