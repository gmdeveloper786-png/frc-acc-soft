@php
    $order = $purchaseOrder ?? null;
    $lines = old('lines', $order
        ? $order->lines->map(fn ($line) => $line->only(['description', 'quantity', 'unit', 'unit_price', 'discount_amount', 'tax_amount', 'account_id', 'service_id']))->all()
        : [['quantity' => '1.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000']]);
@endphp
<form method="POST" action="{{ $order ? route('purchase-orders.update',$order) : route('purchase-orders.store') }}" data-submit-once>@csrf @if($order) @method('PUT') @endif
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $order ? $order->po_number : 'Number is assigned on save' }}</span><h2>Order details</h2></div></div>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="vendor_id">Vendor</label><select class="form-select @error('vendor_id') is-invalid @enderror" id="vendor_id" name="vendor_id" required><option value="">Select vendor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('vendor_id',$order?->vendor_id)==$vendor->id)>{{ $vendor->vendor_code }} — {{ $vendor->name }}</option>@endforeach</select>@error('vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="branch_id">Branch</label><select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id',$order?->branch_id)==$branch->id)>{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="reference">Reference</label><input class="form-control @error('reference') is-invalid @enderror" id="reference" name="reference" maxlength="100" value="{{ old('reference',$order?->reference) }}" placeholder="Quotation or requisition number">@error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="po_date">PO date</label><input class="form-control @error('po_date') is-invalid @enderror" id="po_date" type="date" name="po_date" value="{{ old('po_date',$order?->po_date?->toDateString() ?? today()->toDateString()) }}" required>@error('po_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label" for="expected_delivery_date">Expected delivery date</label><input class="form-control @error('expected_delivery_date') is-invalid @enderror" id="expected_delivery_date" type="date" name="expected_delivery_date" value="{{ old('expected_delivery_date',$order?->expected_delivery_date?->toDateString()) }}">@error('expected_delivery_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-4"><label class="form-label">Currency</label><input class="form-control" value="{{ $order?->currency ?? $appSettings['currency_code'] }}" disabled></div>
        <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="2" maxlength="2000">{{ old('description',$order?->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    </div>
</section>

<section class="surface-card" data-line-items>
    <div class="card-heading"><div><span class="section-kicker">Exact 4-decimal amounts</span><h2>Order lines</h2></div><button class="btn btn-sm btn-outline-primary" type="button" data-add-line>+ Add line</button></div>
    @if($errors->has('lines') || $errors->has('lines.*'))<div class="alert alert-danger">@foreach(collect($errors->get('lines'))->merge(collect($errors->get('lines.*'))->flatten())->unique() as $message)<span class="d-block">{{ $message }}</span>@endforeach</div>@endif
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Item / description</th><th>Qty</th><th>Unit</th><th>Unit price</th><th>Discount</th><th>Tax</th><th>Ledger / service (optional)</th><th class="text-end">Line total</th><th></th></tr></thead>
    <tbody data-line-body>
    @foreach($lines as $index => $line)
        @include('accounting.purchase-orders._line', ['index' => $index, 'line' => $line])
    @endforeach
    </tbody>
    <tfoot><tr><th colspan="7" class="text-end">Order total ({{ $order?->currency ?? $appSettings['currency_code'] }})</th><th class="text-end font-monospace" data-grand-total>0.0000</th><th></th></tr></tfoot></table></div>
    <p class="card-copy">Totals shown here are a preview; the server recalculates them exactly on save. Ledger and service are planning references only — a purchase order creates no accounting entry.</p>
    <template>@include('accounting.purchase-orders._line', ['index' => '__INDEX__', 'line' => ['quantity' => '1.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000']])</template>
    <div class="form-actions"><button class="btn btn-primary">{{ $order ? 'Save draft changes' : 'Save draft' }}</button><a class="btn btn-outline-primary" href="{{ $order ? route('purchase-orders.show',$order) : route('purchase-orders.index') }}">Cancel</a></div>
</section>
</form>
