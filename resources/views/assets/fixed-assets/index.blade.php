@extends('layouts.app') @section('title','Fixed Assets') @section('eyebrow','Fixed Assets') @section('page-title','Fixed Assets')
@section('page-description','The fixed asset register. Registering or activating an asset posts nothing to the General Ledger; cost is recognised by the purchase bill.')
@section('page-actions')
<div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="{{ route('fixed-assets.register') }}">Asset register report</a>
    @can('fixed_assets.create')<a class="btn btn-primary" href="{{ route('fixed-assets.create') }}">+ Register asset</a>@endcan
</div>
@endsection
@section('content')
@include('assets._policy-banner')
<section class="surface-card">
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Asset code, name, location or custodian">
        <select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id')==$branch->id)>{{ $branch->name }}</option>@endforeach</select>
        <select class="form-select" name="category_id"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->code }} — {{ $category->name }}</option>@endforeach</select>
        <select class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Enums\FixedAssetStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('fixed-assets.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Asset code</th><th>Name</th><th>Category</th><th>Branch</th><th>In service</th><th>Status</th><th class="text-end">Cost</th><th class="text-end">Accumulated depreciation</th><th class="text-end">Net book value</th><th>Actions</th></tr></thead><tbody>
    @forelse($assets as $asset)
        @php
            $accumulated = $asset->accumulatedDepreciation();
            $netBookValue = \App\Services\AccountingDecimal::string(\App\Services\AccountingDecimal::of($asset->acquisition_cost)->minus(\App\Services\AccountingDecimal::of($accumulated)));
        @endphp
        <tr>
            <td><code>{{ $asset->asset_code }}</code></td>
            <td>{{ $asset->name }}@if($asset->location || $asset->custodian)<span class="table-subtext">{{ collect([$asset->location, $asset->custodian])->filter()->implode(' · ') }}</span>@endif</td>
            <td>{{ $asset->category->name }}</td>
            <td>{{ $asset->branch->name }}</td>
            <td>{{ $asset->in_service_date->format('d M Y') }}</td>
            <td><span class="status-badge {{ $asset->status->badge() }}">{{ $asset->status->label() }}</span></td>
            <td class="text-end font-monospace">{{ number_format((float)$asset->acquisition_cost,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$accumulated,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$netBookValue,2) }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('fixed-assets.show',$asset) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="10"><x-empty-state title="No fixed assets found" description="Register an asset or change the filters." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $assets->links() }}
</section>
@endsection
