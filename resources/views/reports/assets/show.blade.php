@extends('layouts.app') @section('title', $report->title) @section('eyebrow','Fixed Asset Reports') @section('page-title', $report->title)
@section('page-description', $report->description)
@section('page-actions')
<div class="d-flex gap-2 flex-wrap d-print-none">
    @if($canExport && $report->prompt === null)
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
            <a class="btn btn-outline-primary" href="{{ route('reports.assets.'.$report->key.'.export', ['format' => $format] + request()->except('page')) }}">{{ $label }}</a>
        @endforeach
    @endif
    <a class="btn btn-outline-primary" href="{{ route('reports.assets.index') }}">All reports</a>
</div>
@endsection
@section('content')
@php
    $usesAssetFilters = in_array($report->key, ['register', 'category-summary', 'branch-summary', 'net-book-value'], true);
    $selectedBranch = $scope['consolidated'] ? 'all' : $scope['selected']?->id;
@endphp
<div class="d-print-none">@include('assets._policy-banner')</div>
@if($report->key === 'missed-periods')
    <div class="alert alert-warning" role="alert"><strong>{{ \App\Services\Reports\Operational\AssetReportService::MISSED_PERIOD_LABEL }}.</strong> This report lists exceptions for an accountant to review. It posts nothing and proposes no catch-up entry.</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<section class="surface-card mb-4 d-print-none">
    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
        @foreach($reports as $key => $definition)
            <li class="nav-item"><a class="nav-link {{ $key === $report->key ? 'active' : '' }}" href="{{ route('reports.assets.'.$key) }}">{{ $definition['title'] }}</a></li>
        @endforeach
    </ul>
    <form class="filter-bar filter-bar-wide" method="GET">
        @if($report->key === 'schedule')
            <select class="form-select" name="asset_id" aria-label="Asset"><option value="">Select an asset</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected(request('asset_id')==$asset->id)>{{ $asset->asset_code }} — {{ $asset->name }}</option>@endforeach</select>
        @else
            <select class="form-select" name="branch_id" aria-label="Branch">
                @can('branches.view_all')<option value="all" @selected($selectedBranch==='all')>All branches (consolidated)</option>@endcan
                @foreach($scope['branches'] as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch===$branch->id)>{{ $branch->name }}</option>@endforeach
            </select>
        @endif
        @if($usesAssetFilters || $report->key === 'missed-periods')
            <select class="form-select" name="category_id" aria-label="Category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->code }} — {{ $category->name }}</option>@endforeach</select>
        @endif
        @if($usesAssetFilters)
            <select class="form-select" name="status" aria-label="Status"><option value="">All activated assets</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
            <input class="form-control" type="date" name="as_of" value="{{ request('as_of', now()->toDateString()) }}" aria-label="As of date" title="As of date">
            <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Code, name, location or custodian">
        @endif
        @if($report->key === 'history')
            <select class="form-select" name="status" aria-label="Status"><option value="">Posted and reversed</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select>
            <select class="form-select" name="period_id" aria-label="Accounting period"><option value="">All periods</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected(request('period_id')==$period->id)>{{ $period->name }}</option>@endforeach</select>
            <input class="form-control" type="date" name="date_from" value="{{ request('date_from') }}" aria-label="Period ending from" title="Period ending from">
            <input class="form-control" type="date" name="date_to" value="{{ request('date_to') }}" aria-label="Period ending to" title="Period ending to">
        @endif
        <button class="btn btn-primary">Apply</button>
        <a class="btn btn-outline-primary" href="{{ route('reports.assets.'.$report->key) }}">Reset</a>
    </form>
</section>

<section class="surface-card">
    <div class="card-heading"><div>
        <span class="section-kicker">@foreach($report->meta as $label => $value){{ $label }}: {{ $value }}@if(! $loop->last) · @endif @endforeach</span>
        <h2>{{ $report->title }}</h2>
    </div><span class="status-badge neutral">{{ $rows->total() }} rows</span></div>
    @if($report->prompt)
        <x-empty-state title="Select an asset" :description="$report->prompt" />
    @else
        @include('reports.assets._table', [
            'emptyTitle' => $report->key === 'missed-periods' ? 'No missed period' : ($report->key === 'history' ? 'No posted depreciation' : 'No assets to report'),
            'emptyDescription' => $report->key === 'missed-periods' ? 'Every active asset has posted depreciation for each period up to the latest period posted for its branch.' : ($report->key === 'history' ? 'No depreciation run has been posted for the selected branch and filters.' : 'Only activated assets acquired on or before the as-of date appear here. Register and activate an asset, or change the filters.'),
        ])
    @endif
</section>
@endsection
