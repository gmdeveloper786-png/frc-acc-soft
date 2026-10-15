@php
    $managesFunds = auth()->user()->can('funds.manage');
    $managesGrants = auth()->user()->can('grants.manage');
    $selectedLedgers = collect(old('permitted_account_ids', $fund?->permittedAccounts->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
@endphp
@extends('layouts.app') @section('title', $fund ? 'Edit Fund' : 'New Fund') @section('eyebrow','Funds & Grants') @section('page-title', $fund ? 'Edit Fund '.$fund->code : 'New Fund')
@section('page-description','Fund details need funds.manage; the donor, grant amount and grant period need grants.manage. Posted journal lines keep the fund they were posted with.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('funds.index') }}">Back</a>@endsection
@section('content')
<form method="POST" action="{{ $fund ? route('funds.update',$fund) : route('funds.store') }}" data-submit-once>@csrf @if($fund) @method('PUT') @endif
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Configurable record</span><h2>Fund details</h2></div></div>
    @unless($managesFunds)<div class="alert alert-info">You may change the grant fields only. The other fund details are shown for reference.</div>@endunless
    <fieldset @disabled(! $managesFunds)>
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label" for="code">Code</label><input class="form-control @error('code') is-invalid @enderror" id="code" name="code" maxlength="30" value="{{ old('code',$fund?->code) }}" required placeholder="e.g. GRT-01">@error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-5"><label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="150" value="{{ old('name',$fund?->name) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-2"><label class="form-label" for="type">Type</label><select class="form-select @error('type') is-invalid @enderror" id="type" name="type" required>@foreach(\App\Models\Fund::TYPES as $value => $label)<option value="{{ $value }}" @selected(old('type',$fund?->type)===$value)>{{ $label }}</option>@endforeach</select>@error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-2"><label class="form-label" for="category">Category</label><select class="form-select @error('category') is-invalid @enderror" id="category" name="category" required>@foreach(\App\Models\Fund::CATEGORIES as $value => $label)<option value="{{ $value }}" @selected(old('category',$fund?->category)===$value)>{{ $label }}</option>@endforeach</select>@error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="parent_id">Parent fund (optional)</label><select class="form-select @error('parent_id') is-invalid @enderror" id="parent_id" name="parent_id"><option value="">None — top-level fund</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected(old('parent_id',$fund?->parent_id)==$parent->id)>{{ $parent->label() }}</option>@endforeach</select>@error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror<span class="table-subtext">For example GRT-01 under GRT. One level only.</span></div>
        <div class="col-md-8"><label class="form-label" for="restriction_notes">Restriction notes</label><textarea class="form-control @error('restriction_notes') is-invalid @enderror" id="restriction_notes" name="restriction_notes" rows="2" maxlength="2000">{{ old('restriction_notes',$fund?->restriction_notes) }}</textarea>@error('restriction_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12"><label class="form-label" for="permitted_account_ids">Permitted charge ledgers (optional, restricted funds)</label>
            <select class="form-select @error('permitted_account_ids') is-invalid @enderror" id="permitted_account_ids" name="permitted_account_ids[]" multiple size="8">@foreach($chargeLedgers as $ledger)<option value="{{ $ledger->id }}" @selected(in_array($ledger->id, $selectedLedgers, true))>{{ $ledger->code }} — {{ $ledger->name }} ({{ $ledger->account_type->value }})</option>@endforeach</select>
            @error('permitted_account_ids')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <span class="table-subtext">Leave empty to permit any ledger. When a restricted fund lists ledgers, a charge to another ledger is warned about, or refused once a Director enforces the rule.</span>
        </div>
        <div class="col-12"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active',$fund?->is_active ?? true))><label class="form-check-label" for="is_active">Active — available on new journal lines and transactions</label></div></div>
    </div>
    </fieldset>
</section>
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Grant tracking</span><h2>Grant details</h2></div></div>
    @unless($managesGrants)<div class="alert alert-info">Grant fields need the grants.manage permission and are shown for reference.</div>@endunless
    <fieldset @disabled(! $managesGrants)>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="donor_name">Donor / grantor</label><input class="form-control @error('donor_name') is-invalid @enderror" id="donor_name" name="donor_name" maxlength="255" value="{{ old('donor_name',$fund?->donor_name) }}">@error('donor_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label" for="grant_amount">Grant amount (PKR)</label><input class="form-control text-end @error('grant_amount') is-invalid @enderror" id="grant_amount" name="grant_amount" inputmode="decimal" value="{{ old('grant_amount',$fund?->grant_amount) }}">@error('grant_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-2"><label class="form-label" for="start_date">Start date</label><input class="form-control @error('start_date') is-invalid @enderror" id="start_date" type="date" name="start_date" value="{{ old('start_date',$fund?->start_date?->toDateString()) }}">@error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label" for="end_date">End date</label><input class="form-control @error('end_date') is-invalid @enderror" id="end_date" type="date" name="end_date" value="{{ old('end_date',$fund?->end_date?->toDateString()) }}">@error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    </fieldset>
    <div class="form-actions"><button class="btn btn-primary">{{ $fund ? 'Save changes' : 'Create fund' }}</button><a class="btn btn-outline-primary" href="{{ route('funds.index') }}">Cancel</a></div>
</section>
</form>
@endsection
