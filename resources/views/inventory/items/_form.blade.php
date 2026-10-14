@php
    $item = $item ?? null;
@endphp
@include('inventory._errors')
<form method="POST" action="{{ $item ? route('inventory.items.update',$item) : route('inventory.items.store') }}" data-submit-once>@csrf @if($item) @method('PUT') @endif
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Quantity only — no price or value is kept</span><h2>Item details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="item_code">Item code</label><input class="form-control @error('item_code') is-invalid @enderror" id="item_code" name="item_code" maxlength="40" value="{{ old('item_code',$item?->item_code) }}" required>@error('item_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-8"><label class="form-label" for="name">Name</label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="150" value="{{ old('name',$item?->name) }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="category_id">Category</label><select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required><option value="">Select category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$item?->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select>@error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="unit_of_measure">Unit of measure</label><input class="form-control @error('unit_of_measure') is-invalid @enderror" id="unit_of_measure" name="unit_of_measure" maxlength="30" value="{{ old('unit_of_measure',$item?->unit_of_measure) }}" placeholder="pcs, box, kg" required>@error('unit_of_measure')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="minimum_stock_level">Minimum stock level (per branch)</label><input class="form-control @error('minimum_stock_level') is-invalid @enderror" id="minimum_stock_level" name="minimum_stock_level" inputmode="decimal" value="{{ old('minimum_stock_level',$item?->minimum_stock_level ?? '0.0000') }}">@error('minimum_stock_level')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">Zero switches low-stock alerts off for this item.</div></div>
        <div class="col-md-4"><label class="form-label" for="is_active">Status</label><select class="form-select @error('is_active') is-invalid @enderror" id="is_active" name="is_active"><option value="1" @selected((string) old('is_active',(int) ($item?->is_active ?? true))==='1')>Active</option><option value="0" @selected((string) old('is_active',(int) ($item?->is_active ?? true))==='0')>Inactive — no new movements</option></select>@error('is_active')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="2" maxlength="2000">{{ old('description',$item?->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">{{ $item ? 'Save changes' : 'Create item' }}</button><a class="btn btn-outline-primary" href="{{ route('inventory.items.index') }}">Cancel</a></div>
</section>
</form>
