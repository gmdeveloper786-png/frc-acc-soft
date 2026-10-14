{{-- Export links keep the filters currently on screen. Offered only when a statement is shown. --}}
@if($available)
    @can('financial_statements.export')
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
            <a class="btn btn-outline-primary" href="{{ route('statements.export', ['report' => $reportKey, 'format' => $format] + request()->query()) }}">Export {{ $label }}</a>
        @endforeach
    @endcan
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
@endif
