@extends('layouts.app') @section('title','Asset Register') @section('eyebrow','Fixed Assets') @section('page-title','Asset Register')
@section('page-description','Cost, accumulated depreciation and net book value per branch. Accumulated depreciation is opening depreciation plus posted depreciation runs.')
@section('page-actions')
<div class="d-flex gap-2 d-print-none">
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
    <a class="btn btn-outline-primary" href="{{ route('fixed-assets.index') }}">Back</a>
</div>
@endsection
@section('content')
<div class="d-print-none">@include('assets._policy-banner')</div>
<div class="surface-card mb-4 d-print-none"><form class="row g-3 align-items-end" method="GET">
    <div class="col-md-4"><label class="form-label" for="branch_id">Branch</label><select class="form-select" id="branch_id" name="branch_id"><option value="">All authorized branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->code }} — {{ $category->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" id="include_retired" name="include_retired" value="1" @checked(request()->boolean('include_retired'))><label class="form-check-label" for="include_retired">Include retired assets</label></div></div>
    <div class="col-md-2"><button class="btn btn-primary">Apply</button></div>
</form></div>

@forelse($groups as $group)
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">As of {{ now()->format('d M Y') }} — draft assets excluded</span><h2>{{ $group['branch']->name }}</h2></div><span class="status-badge neutral">{{ $group['rows']->count() }} assets</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Asset code</th><th>Name</th><th>Category</th><th>In service</th><th class="text-end">Life (months)</th><th>Status</th><th class="text-end">Cost</th><th class="text-end">Accumulated depreciation</th><th class="text-end">Net book value</th></tr></thead><tbody>
    @foreach($group['rows'] as $row)
        <tr>
            <td><code>{{ $row['asset']->asset_code }}</code></td>
            <td>{{ $row['asset']->name }}@if($row['asset']->location)<span class="table-subtext">{{ $row['asset']->location }}</span>@endif</td>
            <td>{{ $row['asset']->category->name }}</td>
            <td>{{ $row['asset']->in_service_date->format('d M Y') }}</td>
            <td class="text-end">{{ $row['asset']->useful_life_months }}</td>
            <td>{{ $row['asset']->status->label() }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$row['cost'],2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$row['accumulated'],2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$row['net_book_value'],2) }}</td>
        </tr>
    @endforeach
    </tbody><tfoot><tr><th colspan="6" class="text-end">Branch total ({{ $appSettings['currency_code'] }})</th><th class="text-end font-monospace">{{ number_format((float)$group['cost'],2) }}</th><th class="text-end font-monospace">{{ number_format((float)$group['accumulated'],2) }}</th><th class="text-end font-monospace">{{ number_format((float)$group['net_book_value'],2) }}</th></tr></tfoot></table></div>
</section>
@empty
<section class="surface-card"><x-empty-state title="No assets in the register" description="Only activated assets appear here. Register and activate an asset, or change the filters." /></section>
@endforelse
@endsection
