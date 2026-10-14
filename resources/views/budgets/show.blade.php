@extends('layouts.app') @section('title','Budget '.$budget->name) @section('eyebrow','Budget management') @section('page-title',$budget->name.' — v'.$budget->version)
@section('page-description',$budget->fiscalYear->name.' • '.$budget->branch->name.' • '.($budget->service?->name ?? 'Whole branch').' • '.$budget->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @can('budgets.update') @if($budget->status->isEditable())<a class="btn btn-outline-primary" href="{{ route('budgets.edit',$budget) }}">Edit draft</a>@endif @endcan
    @can('budget_reports.view') @if($budget->status===\App\Enums\BudgetStatus::Approved)<a class="btn btn-primary" href="{{ route('budget-reports.versus-actual',['fiscal_year_id'=>$budget->fiscal_year_id,'branch_id'=>$budget->branch_id,'service_id'=>$budget->service_id]) }}">Budget vs Actual</a>@endif @endcan
    <a class="btn btn-outline-primary" href="{{ route('budgets.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $status = $budget->status;
    $user = auth()->user();
    $isMaker = in_array($user->id, [$budget->created_by, $budget->submitted_by], true);
    $currency = $appSettings['currency_code'];
    $hasOpenRevision = $versions->contains(fn ($version) => $version->status->isOpen());
@endphp
@foreach(['status','approval','reason'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<div class="kpi-grid">
    <x-kpi-card label="Budget total" value="{{ $currency }} {{ number_format((float)$budget->total_amount,2) }}" icon="Σ" tone="navy" status="{{ $status->label() }}" description="Sum of all budget lines, recalculated on the server"/>
    <x-kpi-card label="Version" value="v{{ $budget->version }}" icon="V" tone="teal" status="{{ $versions->count() }} in chain" description="{{ $budget->root_budget_id ? 'Revision of an earlier approved version' : 'First version of this budget' }}"/>
    <x-kpi-card label="Budgeted ledgers" value="{{ $grid->count() }}" icon="L" tone="amber" status="{{ $budget->lines->count() }} lines" description="Active postable income and expense ledgers only"/>
    <x-kpi-card label="Accounting effect" value="None" icon="J" tone="rose" status="Planning record" description="A budget creates no journal entry and blocks no transaction"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Planning record — no General Ledger posting</span><h2>Budget details</h2></div><span class="status-badge {{ $status->badge() }}">{{ $status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>Fiscal year</small><strong class="d-block">{{ $budget->fiscalYear->name }}</strong></div>
        <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $budget->branch->name }}</strong></div>
        <div class="col-md-3"><small>Service</small><strong class="d-block">{{ $budget->service?->name ?? 'Whole branch' }}</strong></div>
        <div class="col-md-3"><small>Prepared by</small><strong class="d-block">{{ $budget->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Submitted by</small><strong class="d-block">{{ $budget->submitter?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Submitted on</small><strong class="d-block">{{ $budget->submitted_at?->format('d M Y H:i') ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approved by</small><strong class="d-block">{{ $budget->approver?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approval date</small><strong class="d-block">{{ $budget->approved_at?->format('d M Y H:i') ?? '—' }}</strong></div>
        @if($budget->revision_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Revision reason:</strong> {{ $budget->revision_reason }}</div></div>@endif
        @if($budget->rejection_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned for correction:</strong> {{ $budget->rejection_reason }}</div></div>@endif
    </div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Allocation per ledger and accounting period</span><h2>Budget lines</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Ledger</th>@foreach($periods as $period)<th class="text-end">{{ $period->name }}</th>@endforeach<th class="text-end">Annual total</th></tr></thead><tbody>
    @forelse($grid as $lines)
        @php
            $account = $lines->first()->account;
            $byPeriod = $lines->keyBy('accounting_period_id');
            $rowTotal = $lines->reduce(fn ($sum, $line) => $sum->plus(\App\Services\AccountingDecimal::of($line->amount)), \Brick\Math\BigDecimal::zero()->toScale(4));
        @endphp
        <tr><td><code>{{ $account->code }}</code> {{ $account->name }}<span class="table-subtext">{{ ucfirst($account->account_type->value) }}</span></td>
            @foreach($periods as $period)<td class="text-end font-monospace">{{ $byPeriod->has($period->id) ? number_format((float)$byPeriod->get($period->id)->amount,2) : '—' }}</td>@endforeach
            <td class="text-end font-monospace">{{ number_format((float)(string)$rowTotal,2) }}</td></tr>
    @empty
        <tr><td colspan="{{ $periods->count() + 2 }}"><x-empty-state title="No budget lines" description="This budget has no lines yet." /></td></tr>
    @endforelse
    </tbody><tfoot><tr><th colspan="{{ $periods->count() + 1 }}" class="text-end">Budget total</th><th class="text-end font-monospace">{{ $currency }} {{ number_format((float)$budget->total_amount,2) }}</th></tr></tfoot></table></div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Workflow</span><h2>Actions</h2></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        @if($status->isEditable()) @can('budgets.submit')
            <form method="POST" action="{{ route('budgets.submit',$budget) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Submit this budget for approval? It can no longer be edited unless it is returned.">Submit for approval</button></form>
        @endcan @endif

        @if($status===\App\Enums\BudgetStatus::Submitted)
            @if($canApprove)
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#approveModal">Approve</button>
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal">Return for correction</button>
            @else
                <span class="status-badge pending">{{ $isMaker ? 'Awaiting approval by another authorized user' : 'Awaiting authorized approver' }}</span>
            @endif
        @endif

        @if($status===\App\Enums\BudgetStatus::Approved)
            @can('budgets.revise')
                @if($hasOpenRevision)<span class="status-badge pending">A revision is already in progress</span>
                @else<button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#reviseModal">Revise budget</button>@endif
            @endcan
            <span class="status-badge success">Approved budgets are immutable; changes are made through a new version</span>
        @endif
        @if($status===\App\Enums\BudgetStatus::Superseded)<span class="status-badge neutral">No further actions — this version was superseded by a later approved version</span>@endif
    </div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Every version stays on record</span><h2>Version history</h2></div><span class="status-badge neutral">{{ $versions->count() }} versions</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Version</th><th>Status</th><th class="text-end">Total</th><th>Prepared by</th><th>Approved by</th><th>Approved on</th><th>Revision reason</th><th></th></tr></thead><tbody>
    @foreach($versions as $version)
        <tr><td><strong>v{{ $version->version }}</strong>@if($version->id===$budget->id)<span class="table-subtext">This version</span>@endif</td><td><span class="status-badge {{ $version->status->badge() }}">{{ $version->status->label() }}</span></td><td class="text-end font-monospace">{{ number_format((float)$version->total_amount,2) }}</td><td>{{ $version->creator?->name ?? '—' }}</td><td>{{ $version->approver?->name ?? '—' }}</td><td>{{ $version->approved_at?->format('d M Y H:i') ?? '—' }}</td><td>{{ $version->revision_reason ?: '—' }}</td><td>@if($version->id!==$budget->id)<a class="btn btn-sm btn-outline-primary" href="{{ route('budgets.show',$version) }}">View</a>@endif</td></tr>
    @endforeach
    </tbody></table></div>
</section>

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Append-only record</span><h2>Approval history</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>When</th><th>Action</th><th>By</th><th>Status change</th><th class="text-end">Total</th><th>Comments</th></tr></thead><tbody>
    @foreach($budget->approvals as $entry)
        <tr><td>{{ $entry->created_at?->format('d M Y H:i') }}</td><td><strong>{{ ucfirst($entry->action) }}</strong></td><td>{{ $entry->actor?->name ?? '—' }}</td><td>{{ $entry->from_status ? ucfirst($entry->from_status).' → ' : '' }}{{ ucfirst($entry->to_status) }}</td><td class="text-end font-monospace">{{ number_format((float)$entry->total_amount,2) }}</td><td>{{ $entry->comments ?: '—' }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>

@if($canApprove)
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('budgets.approve',$budget) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="approveModalTitle">Approve {{ $budget->name }} v{{ $budget->version }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Approve a budget of {{ $currency }} {{ number_format((float)$budget->total_amount,2) }} for {{ $budget->branch->name }}? @if($budget->root_budget_id)The currently approved version will be superseded. @endif Approval creates no accounting entry.</p><label class="form-label" for="approveComments">Comments (optional)</label><textarea class="form-control" id="approveComments" name="comments" rows="2" maxlength="2000"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Approve budget</button></div>
</form></div></div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('budgets.reject',$budget) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="rejectModalTitle">Return {{ $budget->name }} v{{ $budget->version }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label" for="rejectReason">Reason</label><textarea class="form-control" id="rejectReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Return for correction</button></div>
</form></div></div>
@endif

@can('budgets.revise') @if($status===\App\Enums\BudgetStatus::Approved && ! $hasOpenRevision)
<div class="modal fade" id="reviseModal" tabindex="-1" aria-labelledby="reviseModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('budgets.revise',$budget) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="reviseModalTitle">Revise {{ $budget->name }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>A new draft version {{ $versions->max('version') + 1 }} is created with the same lines. Version {{ $budget->version }} stays approved until the revision is approved, then it is marked superseded.</p><label class="form-label" for="reviseReason">Reason for revision</label><textarea class="form-control" id="reviseReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create revision</button></div>
</form></div></div>
@endif @endcan
@endsection
