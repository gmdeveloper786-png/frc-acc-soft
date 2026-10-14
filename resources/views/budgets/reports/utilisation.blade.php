@extends('layouts.app') @section('title','Budget Utilisation') @section('eyebrow','Budget reports') @section('page-title','Budget Utilisation and Warnings')
@section('page-description','Budgeted ledgers at or above the configured warning threshold, and the recorded alert log. Alerts inform; they never block a transaction.')
@section('page-actions')
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
@endsection
@section('content')
@include('budgets.reports._filters', ['requireBranch' => false, 'showService' => false])

@if(! $year)
    <section class="surface-card"><x-empty-state title="Select a fiscal year" description="No current fiscal year is set. Choose the fiscal year to review." /></section>
@elseif($approvedCount === 0)
    <section class="surface-card mb-4"><x-empty-state title="No approved budget for {{ $year->name }}" description="There is no approved budget for this selection, so utilisation cannot be measured. Draft, submitted and rejected budgets are not reported." /></section>
@else
    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">{{ $year->name }} · warning from {{ $warningPercentage }}% (configured) · over above 100%</span><h2>Ledgers needing attention</h2></div><span class="status-badge {{ count($flagged) ? 'pending' : 'success' }}">{{ count($flagged) }} flagged</span></div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Branch / budget</th><th>Account</th><th class="text-end">Budget allocation</th><th class="text-end">Actual</th><th class="text-end">Remaining</th><th class="text-end">Variance</th><th class="text-end">Utilisation %</th><th>Status</th><th class="text-end">PO commitments (not in Actual)</th></tr></thead><tbody>
        @forelse($flagged as $row)
            <tr><td>{{ $row['source']->branch->name }}<span class="table-subtext">{{ $row['source']->name }} v{{ $row['source']->version }} · {{ $row['source']->service?->name ?? 'Whole branch' }}</span></td><td><code>{{ $row['account']->code }}</code> {{ $row['account']->name }}</td>@include('budgets.reports._figures', ['figures' => $row])</tr>
        @empty
            <tr><td colspan="9"><x-empty-state title="No ledger has reached the warning threshold" description="Every budgeted expense ledger of the {{ $approvedCount }} approved {{ Str::plural('budget', $approvedCount) }} is below {{ $warningPercentage }}% for the selected periods." /></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
@endif

@if($alerts !== null)
    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">Recorded once, when first detected · append-only</span><h2>Alert log</h2></div><span class="status-badge neutral">{{ $alerts->total() }} recorded</span></div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Detected</th><th>Level</th><th>Branch / budget</th><th>Account</th><th>Basis</th><th class="text-end">Budget at detection</th><th class="text-end">Actual at detection</th><th class="text-end">Utilisation %</th><th>Detected by</th></tr></thead><tbody>
        @forelse($alerts as $alert)
            <tr><td>{{ $alert->detected_at->format('d M Y H:i') }}</td><td><span class="status-badge {{ $alert->level->badge() }}">{{ $alert->level->label() }}</span></td><td>{{ $alert->budget->branch->name }}<span class="table-subtext">{{ $alert->budget->name }} v{{ $alert->budget->version }} · {{ $alert->budget->service?->name ?? 'Whole branch' }}</span></td><td><code>{{ $alert->account->code }}</code> {{ $alert->account->name }}</td><td>{{ $alert->accountingPeriod?->name ?? 'Year to date' }}<span class="table-subtext">Threshold {{ $alert->warning_percentage }}%</span></td><td class="text-end font-monospace">{{ number_format((float)$alert->budget_amount,2) }}</td><td class="text-end font-monospace">{{ number_format((float)$alert->actual_amount,2) }}</td><td class="text-end font-monospace">{{ $alert->utilisation_percentage !== null ? number_format((float)$alert->utilisation_percentage,2).'%' : 'Unbudgeted spend' }}</td><td>{{ $alert->detector?->name ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="9"><x-empty-state title="No alerts recorded" description="An alert is recorded the first time a report finds a budget line at the warning threshold or over budget." /></td></tr>
        @endforelse
        </tbody></table></div>
        {{ $alerts->links() }}
    </section>
@endif
@endsection
