{{-- Hierarchy rows of one statement section. Needs $tree and $columns (list of column keys). --}}
@forelse($tree['rows'] as $row)
    <tr>
        <td style="padding-left: {{ 12 + $row['depth'] * 18 }}px" class="{{ $row['is_ledger'] ? '' : 'fw-semibold' }}"><code>{{ $row['code'] }}</code> {{ $row['name'] }}</td>
        @foreach($columns as $column)
            <td class="text-end font-monospace {{ $row['is_ledger'] ? '' : 'fw-semibold' }}">{{ \App\Services\AccountingDecimal::format($row['amounts'][$column], 2, 4) }}</td>
        @endforeach
    </tr>
@empty
    <tr><td colspan="{{ count($columns) + 1 }}" class="table-subtext">No posted activity.</td></tr>
@endforelse
