{{-- Budget, Actual, Remaining, Variance, Utilisation, Status and PO commitment cells for one set of figures. --}}
@if($figures === null || ($figures['has_lines'] ?? true) === false)
    <td class="text-end" colspan="7">No budget lines</td>
@else
    <td class="text-end font-monospace">{{ number_format((float)$figures['budget'],2) }}</td>
    <td class="text-end font-monospace">{{ number_format((float)$figures['actual'],2) }}</td>
    <td class="text-end font-monospace">{{ number_format((float)$figures['remaining'],2) }}</td>
    <td class="text-end font-monospace">{{ number_format((float)$figures['variance'],2) }}</td>
    <td class="text-end font-monospace">@if($figures['utilisation'] !== null){{ number_format((float)$figures['utilisation'],2) }}%@elseif($figures['status']===\App\Enums\BudgetUtilisationStatus::Unbudgeted)Unbudgeted spend @else — @endif</td>
    <td><span class="status-badge {{ $figures['status']->badge() }}">{{ $figures['status']->label() }}</span></td>
    <td class="text-end font-monospace">{{ ($figures['commitments'] ?? null) !== null ? number_format((float)$figures['commitments'],2) : '—' }}</td>
@endif
