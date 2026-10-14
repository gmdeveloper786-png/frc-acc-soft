{{-- Renders an OperationalReport: the page of detail rows, then its total rows. Figures are exact decimal strings. --}}
@php
    $numeric = ['money', 'quantity', 'number', 'percent'];
    $cell = function (array $column, array $row): string {
        $value = $row[$column['key']] ?? '';
        if ($value === '' || $value === null || ! is_numeric($value)) {
            return (string) $value;
        }

        return match ($column['type'] ?? 'text') {
            'money', 'percent' => number_format((float) $value, 2),
            'quantity' => number_format((float) $value, 4),
            default => (string) $value,
        };
    };
@endphp
@foreach($report->warnings as $warning)
    <div class="alert alert-danger" role="alert"><strong>Exception.</strong> {{ $warning }}</div>
@endforeach
@if($rows->total() === 0)
    <x-empty-state :title="$emptyTitle" :description="$emptyDescription" />
@else
    <div class="table-responsive"><table class="table accounting-table">
        <thead><tr>@foreach($report->columns as $column)<th class="{{ in_array($column['type'] ?? 'text', $numeric, true) ? 'text-end' : '' }}">{{ $column['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($rows as $row)
            <tr>@foreach($report->columns as $column)<td class="{{ in_array($column['type'] ?? 'text', $numeric, true) ? 'text-end font-monospace' : '' }}">{{ $cell($column, $row) }}</td>@endforeach</tr>
        @endforeach
        </tbody>
        @if($report->totals !== [])
            <tfoot>
            @foreach($report->totals as $row)
                <tr>@foreach($report->columns as $column)<th class="{{ in_array($column['type'] ?? 'text', $numeric, true) ? 'text-end font-monospace' : '' }}">{{ $cell($column, $row) }}</th>@endforeach</tr>
            @endforeach
            </tfoot>
        @endif
    </table></div>
    @if($report->totals !== [] && $rows->hasPages())<p class="table-subtext">Totals cover all {{ $rows->total() }} rows of the report, not only this page.</p>@endif
    {{ $rows->links() }}
@endif
@if($report->note)<p class="table-subtext mt-3">{{ $report->note }}</p>@endif
