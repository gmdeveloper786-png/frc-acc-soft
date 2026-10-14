@extends('layouts.app') @section('title', $report->title) @section('eyebrow','Inventory Reports') @section('page-title', $report->title)
@section('page-description', $report->description)
@section('page-actions')
<div class="d-flex gap-2 flex-wrap d-print-none">
    @if($canExport)
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
            <a class="btn btn-outline-primary" href="{{ route('reports.inventory.'.$report->key.'.export', ['format' => $format] + request()->except('page')) }}">{{ $label }}</a>
        @endforeach
    @endif
    <a class="btn btn-outline-primary" href="{{ route('reports.inventory.index') }}">All reports</a>
</div>
@endsection
@section('content')
@php
    $selectedBranch = $scope['consolidated'] ? 'all' : $scope['selected']?->id;
    $hasLocation = in_array($report->key, ['stock-balance', 'movements', 'receipts', 'issues', 'issued-to', 'transfers', 'adjustments'], true);
@endphp
@if($errors->any())
    <div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<section class="surface-card mb-4 d-print-none">
    <ul class="nav nav-pills flex-wrap gap-1 mb-3">
        @foreach($reports as $key => $definition)
            <li class="nav-item"><a class="nav-link {{ $key === $report->key ? 'active' : '' }}" href="{{ route('reports.inventory.'.$key) }}">{{ $definition['title'] }}</a></li>
        @endforeach
    </ul>
    <form class="filter-bar filter-bar-wide" method="GET">
        <select class="form-select" name="branch_id" aria-label="Branch">
            @can('branches.view_all')<option value="all" @selected($selectedBranch==='all')>All branches (consolidated)</option>@endcan
            @foreach($scope['branches'] as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch===$branch->id)>{{ $branch->name }}</option>@endforeach
        </select>
        <select class="form-select" name="item_id" aria-label="Item"><option value="">All items</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(request('item_id')==$item->id)>{{ $item->item_code }} — {{ $item->name }}</option>@endforeach</select>
        <select class="form-select" name="category_id" aria-label="Category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select>
        @if($hasLocation)
            <select class="form-select" name="location_id" aria-label="Location"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id')==$location->id)>{{ $location->branch->name }} — {{ $location->name }}</option>@endforeach</select>
        @endif
        @if($report->key === 'movements')
            <select class="form-select" name="type" aria-label="Movement type"><option value="">All types</option>@foreach($types as $type)<option value="{{ $type->value }}" @selected(request('type')===$type->value)>{{ $type->label() }}</option>@endforeach</select>
        @endif
        @if($report->key === 'adjustments')
            <select class="form-select" name="status" aria-label="Status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status === \App\Enums\InventoryMovementStatus::Posted ? 'Approved' : $status->label() }}</option>@endforeach</select>
        @endif
        @if(in_array($report->key, ['issues', 'issued-to'], true))
            <input class="form-control" name="issued_to" value="{{ request('issued_to') }}" placeholder="Issued to">
        @endif
        @if($dated)
            <input class="form-control" type="date" name="date_from" value="{{ request('date_from', now()->startOfMonth()->toDateString()) }}" aria-label="Date from" title="Date from">
            <input class="form-control" type="date" name="date_to" value="{{ request('date_to', now()->toDateString()) }}" aria-label="Date to" title="Date to">
        @endif
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="{{ in_array($report->key, ['stock-balance', 'branch-summary', 'low-stock'], true) ? 'Item code or name' : 'Reference, recipient or notes' }}">
        <button class="btn btn-primary">Apply</button>
        <a class="btn btn-outline-primary" href="{{ route('reports.inventory.'.$report->key) }}">Reset</a>
    </form>
</section>

<section class="surface-card">
    <div class="card-heading"><div>
        <span class="section-kicker">@foreach($report->meta as $label => $value){{ $label }}: {{ $value }}@if(! $loop->last) · @endif @endforeach</span>
        <h2>{{ $report->title }}</h2>
    </div><span class="status-badge {{ $report->warnings === [] ? 'neutral' : 'danger' }}">{{ $rows->total() }} rows</span></div>
    @include('reports.inventory._table', [
        'emptyTitle' => $report->key === 'low-stock' ? 'No low stock' : 'Nothing to report',
        'emptyDescription' => $report->key === 'low-stock' ? 'No active item is at or below its minimum stock level in the selected branch.' : 'No record matches the selected branch, dates and filters.',
    ])
</section>
@endsection
