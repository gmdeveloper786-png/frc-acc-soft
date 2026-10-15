{{-- Export links keep the filters on screen. Needs $available and $exportRoute (a closure taking the format). --}}
@if($available)
    @can('financial_statements.export')
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
            <a class="btn btn-outline-primary" href="{{ $exportRoute($format) }}">Export {{ $label }}</a>
        @endforeach
    @endcan
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
@endif
