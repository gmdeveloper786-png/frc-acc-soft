@extends('layouts.app') @section('title','Budget vs Actual') @section('eyebrow','Budget reports') @section('page-title','Budget vs Actual')
@section('page-description','Approved budget compared with posted General Ledger activity. Warnings and overruns are information only; no transaction is blocked.')
@section('page-actions')
<div class="d-flex gap-2">
    @can('budget_reports.export') @if($report)
        @foreach(['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $formatLabel)<a class="btn btn-outline-primary" href="{{ route('budget-reports.versus-actual.export', ['format' => $format, ...request()->query()]) }}">{{ $formatLabel }}</a>@endforeach
    @endif @endcan
    <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print</button>
</div>
@endsection
@section('content')
@include('budgets.reports._filters', ['requireBranch' => true, 'showService' => true, 'showVersion' => true])

@if(! $year || ! request()->filled('branch_id'))
    <section class="surface-card"><x-empty-state title="Select a fiscal year and branch" description="The report is produced for the approved budget of one fiscal year, branch and service scope." /></section>
@elseif($missingBudget)
    <section class="surface-card"><x-empty-state title="No approved budget for this selection" description="There is no approved budget for the selected fiscal year, branch and service, so there is nothing to compare actual activity against. Draft, submitted and rejected budgets are not reported." /></section>
@else
    @php
        $budget = $report['budget'];
        $currency = $appSettings['currency_code'];
        $expense = $report['totals']['expense'];
        $income = $report['totals']['income'];
        $indent = fn (int $depth): int => $depth * 16;
        $isSuperseded = $budget->status === \App\Enums\BudgetStatus::Superseded;
        $current = $versions->firstWhere('status', \App\Enums\BudgetStatus::Approved);
        $tableRows = $layout === 'hierarchy' ? $report['hierarchy'] : $report['rows'];
    @endphp
    @if($isSuperseded)
        <div class="alert alert-warning" role="alert"><strong>Superseded version.</strong> You are viewing version {{ $budget->version }} of this budget, which is no longer in force{{ $current ? ': version '.$current->version.' is the approved budget' : '' }}. Actual is the same posted ledger activity; only the budget column differs. No alert is recorded against a superseded version.</div>
    @endif
    <div class="kpi-grid">
        @if($expense['has_lines'])
            <x-kpi-card label="Expense budget" value="{{ $currency }} {{ number_format((float)$expense['budget'],2) }}" icon="B" tone="navy" status="Allocation" description="Budget lines of the selected periods"/>
            <x-kpi-card label="Expense actual" value="{{ $currency }} {{ number_format((float)$expense['actual'],2) }}" icon="A" tone="teal" status="Posted ledger" description="Posted journals only; reversals net to zero"/>
            <x-kpi-card label="Remaining" value="{{ $currency }} {{ number_format((float)$expense['remaining'],2) }}" icon="R" tone="amber" status="Budget − actual" description="Variance (actual − budget): {{ number_format((float)$expense['variance'],2) }}"/>
            <x-kpi-card label="Utilisation" value="{{ $expense['utilisation'] !== null ? number_format((float)$expense['utilisation'],2).'%' : '—' }}" icon="%" tone="rose" status="{{ $expense['status']->label() }}" description="Warning from {{ $report['warning_percentage'] }}% (configured); over above 100%"/>
        @else
            <x-kpi-card label="Income budget" value="{{ $currency }} {{ number_format((float)$income['budget'],2) }}" icon="B" tone="navy" status="Target" description="Budget lines of the selected periods"/>
            <x-kpi-card label="Income actual" value="{{ $currency }} {{ number_format((float)$income['actual'],2) }}" icon="A" tone="teal" status="Posted ledger" description="Posted journals only; reversals net to zero"/>
        @endif
    </div>

    <section class="surface-card mb-4">
        <div class="card-heading"><div><span class="section-kicker">{{ $budget->fiscalYear->name }} · {{ $budget->branch->name }} · {{ $budget->service?->name ?? 'Whole branch' }} · {{ $report['from']->name }} to {{ $report['to']->name }}</span><h2>{{ $budget->name }} — v{{ $budget->version }}</h2></div><span class="status-badge {{ $budget->status->badge() }}">{{ $isSuperseded ? 'Superseded version' : 'Approved version' }}</span></div>
        <p class="card-copy">Actual = posted journal lines of this branch{{ $budget->service ? ' tagged with this service' : '' }} in the selected periods (expense: debit − credit; income: credit − debit). Group rows add each budgeted ledger once. PO commitments are the unbilled value of approved or issued purchase orders dated in this fiscal year; they are shown for information and are <strong>not</strong> part of Actual.</p>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Account{{ $layout === 'hierarchy' ? ' (class › group › sub-group › ledger)' : '' }}</th><th class="text-end">{{ $isSuperseded ? 'Superseded budget' : 'Approved budget' }}</th><th class="text-end">Actual (posted)</th><th class="text-end">Remaining</th><th class="text-end">Variance</th><th class="text-end">Utilisation %</th><th>Status</th><th class="text-end">PO commitments (not in Actual)</th></tr></thead><tbody>
        @foreach($tableRows as $row)
            <tr @class(['fw-semibold' => ! $row['is_ledger']])><td style="padding-left: {{ $indent($row['depth']) + 12 }}px"><code>{{ $row['account']->code }}</code> {{ $row['account']->name }}@unless($row['is_ledger'])<span class="table-subtext">{{ str($row['account']->level->value)->headline() }} · {{ $row['ledger_count'] }} budgeted {{ Str::plural('ledger', $row['ledger_count']) }}</span>@endunless</td>
                @include('budgets.reports._figures', ['figures' => $row])</tr>
        @endforeach
        </tbody><tfoot>
            @if($expense['has_lines'])<tr><th>Total expenses (each ledger once)</th>@include('budgets.reports._figures', ['figures' => $expense])</tr>@endif
            @if($income['has_lines'])<tr><th>Total income (each ledger once)</th>@include('budgets.reports._figures', ['figures' => $income])</tr>@endif
        </tfoot></table></div>
    </section>

    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">One row per budget line and accounting period</span><h2>Line detail by period</h2></div></div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Account</th><th>Period</th><th class="text-end">Budget allocation</th><th class="text-end">Actual</th><th class="text-end">Remaining</th><th class="text-end">Variance</th><th class="text-end">Utilisation %</th><th>Status</th><th class="text-end">PO commitments</th></tr></thead><tbody>
        @php $hasDetail = false; @endphp
        @foreach($report['rows'] as $row) @foreach($row['periods'] as $periodRow)
            @php $hasDetail = true; @endphp
            <tr><td><code>{{ $row['account']->code }}</code> {{ $row['account']->name }}</td><td>{{ $periodRow['period']->name }}@unless($periodRow['has_budget_line'])<span class="table-subtext">No budget line for this period</span>@endunless</td>@include('budgets.reports._figures', ['figures' => $periodRow])</tr>
        @endforeach @endforeach
        @unless($hasDetail)<tr><td colspan="9"><x-empty-state title="No budget lines or activity in these periods" description="The approved budget has no allocation and the ledger has no posted activity for the selected period range." /></td></tr>@endunless
        </tbody></table></div>
        <p class="card-copy">PO commitments are tracked per ledger for the fiscal year, not per period, so they are shown in the account table above only.</p>
    </section>
@endif
@endsection
