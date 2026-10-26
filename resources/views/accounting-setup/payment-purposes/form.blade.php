@extends('layouts.app')
@section('title', $purpose->exists ? 'Edit Payment Purpose' : 'Add Payment Purpose')
@section('eyebrow', 'Payment Purposes')
@section('page-title', $purpose->exists ? 'Edit '.$purpose->name : 'Add Payment Purpose')

@php($locked = $locked ?? false)
@section('content')
<form class="surface-card" method="POST" action="{{ $purpose->exists ? route('payment-purposes.update', $purpose) : route('payment-purposes.store') }}">
    @csrf
    @if($purpose->exists) @method('PUT') @endif
    <div class="form-stack">
        @if($locked)<div class="alert alert-info">Payments already use this purpose. Its code, accounting behaviour and debit ledger are fixed; the name, description, order and status can change. Past payments keep the name they were saved with.</div>@endif
        <div class="form-grid">
            <div>
                <label class="form-label" for="purpose-name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" id="purpose-name" name="name" value="{{ old('name', $purpose->name) }}" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="purpose-code">Code</label>
                <input class="form-control @error('code') is-invalid @enderror" id="purpose-code" name="code" value="{{ old('code', $purpose->code) }}" maxlength="50" required @readonly($locked)>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="form-label" for="purpose-behaviour">Accounting behaviour</label>
                @if($locked)<input type="hidden" name="behaviour" value="{{ $purpose->behaviour->value }}">@endif
                <select class="form-select @error('behaviour') is-invalid @enderror" id="purpose-behaviour" @if($locked) disabled @else name="behaviour" @endif required>
                    @foreach(\App\Enums\PaymentBehaviour::cases() as $behaviour)
                        <option value="{{ $behaviour->value }}" data-ledger="{{ $behaviour->usesDebitLedger() ? '1' : '0' }}" @selected(old('behaviour', $purpose->behaviour?->value) === $behaviour->value)>{{ $behaviour->label() }}</option>
                    @endforeach
                </select>
                @error('behaviour')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text">The behaviour decides the accounting. Purposes only name and organise it.</small>
            </div>
            <div id="purpose-ledger-field">
                <label class="form-label" for="purpose-ledger">Approved expense ledger <small class="text-muted">(optional)</small></label>
                @if($locked)<input type="hidden" name="debit_account_id" value="{{ $purpose->debit_account_id }}">@endif
                <select class="form-select @error('debit_account_id') is-invalid @enderror" id="purpose-ledger" @if($locked) disabled @else name="debit_account_id" @endif>
                    <option value="">Chosen on each payment</option>
                    @foreach($expenseLedgers as $ledger)
                        <option value="{{ $ledger->id }}" @selected((string) old('debit_account_id', $purpose->debit_account_id) === (string) $ledger->id)>{{ $ledger->code }} — {{ $ledger->name }}</option>
                    @endforeach
                </select>
                @error('debit_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text">Only for a direct expense. Other behaviours take the debit from their own record.</small>
            </div>
            <div class="form-span-2">
                <label class="form-label" for="purpose-description">Description (optional)</label>
                <textarea class="form-control" id="purpose-description" name="description" rows="2" maxlength="2000">{{ old('description', $purpose->description) }}</textarea>
            </div>
            <div>
                <label class="form-label" for="purpose-order">Display order</label>
                <input class="form-control" type="number" min="0" id="purpose-order" name="sort_order" value="{{ old('sort_order', $purpose->sort_order ?? 0) }}">
            </div>
            <div>
                <input type="hidden" name="is_active" value="0">
                <label class="choice-card"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((string) old('is_active', $purpose->is_active ? '1' : '0') === '1')><span><strong>Active</strong><small>Inactive purposes are not offered on Create Payment.</small></span></label>
            </div>
        </div>
        <div class="form-actions"><a class="btn btn-outline-primary" href="{{ route('payment-purposes.index') }}">Cancel</a><button class="btn btn-primary">Save purpose</button></div>
    </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const behaviour = document.getElementById('purpose-behaviour');
    const field = document.getElementById('purpose-ledger-field');
    const ledger = document.getElementById('purpose-ledger');
    const refresh = () => {
        const applies = behaviour.selectedOptions[0]?.dataset.ledger === '1';
        field.hidden = !applies;
        if (!applies && !ledger.disabled) {
            ledger.value = '';
        }
    };
    behaviour.addEventListener('change', refresh);
    refresh();
});
</script>
@endsection
