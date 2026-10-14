@extends('layouts.app') @section('title','New Budget') @section('eyebrow','Budget management') @section('page-title','New Budget')
@section('page-description','Saved as a draft. A budget is a planning record: it creates no journal entry and blocks no transaction.')
@section('content')
@if($years->isEmpty() || ! $year)
    <section class="surface-card"><x-empty-state title="No fiscal year available" description="A budget is prepared for an open or draft fiscal year. Create the fiscal year and its accounting periods first." /></section>
@else
    <section class="surface-card mb-4">
        <form class="filter-bar" method="GET" action="{{ route('budgets.create') }}">
            <select class="form-select" name="fiscal_year_id" aria-label="Fiscal year">@foreach($years as $option)<option value="{{ $option->id }}" @selected($option->id===$year->id)>{{ $option->name }} ({{ ucfirst($option->status->value) }})</option>@endforeach</select>
            <button class="btn btn-outline-primary">Load periods of this fiscal year</button>
        </form>
    </section>
    @if($periods->isEmpty())
        <section class="surface-card"><x-empty-state title="{{ $year->name }} has no accounting periods" description="Budget amounts are entered per accounting period. Generate the periods of this fiscal year first." /></section>
    @else
        @include('budgets._form')
    @endif
@endif
@endsection
