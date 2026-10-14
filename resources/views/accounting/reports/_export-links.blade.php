{{-- Export buttons that keep the screen's current filters. Expects $exportRoute and optional $exportParameters. --}}
@php
    $exportQuery = collect(request()->query())->except(['page', 'format', 'kind'])->all();
@endphp
<div class="btn-group" role="group" aria-label="Export this report">
    @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $exportFormat => $exportLabel)
        <a class="btn btn-outline-primary" href="{{ route($exportRoute, [...$exportQuery, ...($exportParameters ?? []), 'format' => $exportFormat]) }}">{{ $exportLabel }}</a>
    @endforeach
</div>
