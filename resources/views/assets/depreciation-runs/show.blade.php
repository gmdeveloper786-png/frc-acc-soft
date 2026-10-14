@extends('layouts.app') @section('title','Depreciation Run #'.$run->id) @section('eyebrow','Fixed Assets') @section('page-title','Depreciation Run #'.$run->id)
@section('page-description',$run->branch->name.' • '.$run->accountingPeriod->name.' • '.$run->status->label())
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('depreciation-runs.index') }}">Back</a>@endsection
@section('content')
@php
    $status = $run->status;
    $user = auth()->user();
    $isMaker = in_array($user->id, [$run->created_by, $run->submitted_by], true);
    $currency = $appSettings['currency_code'];
    $released = $run->lines->contains(fn ($line) => $line->active_key === null);
@endphp
@include('assets._policy-banner')
@foreach(['status','approval','policy','accounting_period_id','reversal_date','reason','asset_account_id','accumulated_depreciation_account_id','depreciation_expense_account_id','lines'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<div class="kpi-grid">
    <x-kpi-card label="Total depreciation" value="{{ $currency }} {{ number_format((float)$run->total_amount,2) }}" icon="Σ" tone="navy" status="{{ $status->label() }}" description="{{ $run->lines->count() }} assets in this run"/>
    <x-kpi-card label="Accounting period" value="{{ $run->accountingPeriod->name }}" icon="P" tone="teal" status="{{ ucfirst($run->accountingPeriod->status->value) }}" description="{{ $run->accountingPeriod->start_date->format('d M Y') }} – {{ $run->accountingPeriod->end_date->format('d M Y') }}"/>
    <x-kpi-card label="Convention" value="{{ ucfirst(str_replace('_',' ',$run->convention->value)) }}" icon="C" tone="amber" status="Snapshot" description="{{ $run->convention->label() }}"/>
    <x-kpi-card label="Journal" :value="$run->journalEntry?->voucher_number" icon="J" tone="rose" status="{{ $run->journalEntry ? ucfirst($run->journalEntry->status->value) : 'Not posted' }}" description="{{ $run->journalEntry ? 'Dated '.$run->journalEntry->transaction_date->format('d M Y') : 'One journal is created when the run is posted' }}"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Maker-checker</span><h2>Run details</h2></div><span class="status-badge {{ $status->badge() }}">{{ $status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>Prepared by</small><strong class="d-block">{{ $run->creator?->name ?? '—' }}</strong><span class="table-subtext">{{ $run->created_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-3"><small>Submitted by</small><strong class="d-block">{{ $run->submitter?->name ?? '—' }}</strong><span class="table-subtext">{{ $run->submitted_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-3"><small>Approved by</small><strong class="d-block">{{ $run->approver?->name ?? '—' }}</strong><span class="table-subtext">{{ $run->approved_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-3"><small>Posted by</small><strong class="d-block">{{ $run->poster?->name ?? '—' }}</strong><span class="table-subtext">{{ $run->posted_at?->format('d M Y H:i') }}</span></div>
        @if($run->journalEntry)<div class="col-md-6"><small>Journal voucher</small><strong class="d-block">@can('journals.view')<a href="{{ route('journals.show',$run->journalEntry) }}">{{ $run->journalEntry->voucher_number }}</a>@else {{ $run->journalEntry->voucher_number }} @endcan</strong></div>@endif
        @if($run->rejection_reason && $status===\App\Enums\DepreciationRunStatus::Draft)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned for correction:</strong> {{ $run->rejection_reason }}. The lines below are released; submitting recalculates them.</div></div>@endif
        @if($status===\App\Enums\DepreciationRunStatus::Reversed)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Reversed by {{ $run->reverser?->name ?? '—' }} on {{ $run->reversed_at?->format('d M Y H:i') }}:</strong> {{ $run->reversal_reason }}. These lines no longer count towards accumulated depreciation.</div></div>@endif
    </div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $status===\App\Enums\DepreciationRunStatus::Posted ? 'Posted figures' : ($released ? 'Released lines — do not count' : 'Calculated figures — not posted') }}</span><h2>Assets in this run</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Asset</th><th>Expense ledger</th><th>Accumulated depreciation ledger</th><th class="text-end">Accumulated before</th><th class="text-end">Depreciation</th><th class="text-end">Accumulated after</th><th class="text-end">Net book value after</th></tr></thead><tbody>
    @forelse($run->lines as $line)
        <tr>
            <td>@can('fixed_assets.view')<a href="{{ route('fixed-assets.show',$line->asset) }}"><code>{{ $line->asset->asset_code }}</code></a>@else<code>{{ $line->asset->asset_code }}</code>@endcan<span class="table-subtext">{{ $line->asset->name }}</span></td>
            <td>{{ $line->asset->depreciationExpenseAccount?->code }}<span class="table-subtext">{{ $line->asset->depreciationExpenseAccount?->name }}</span></td>
            <td>{{ $line->asset->accumulatedDepreciationAccount?->code }}<span class="table-subtext">{{ $line->asset->accumulatedDepreciationAccount?->name }}</span></td>
            <td class="text-end font-monospace">{{ number_format((float)$line->accumulated_before,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line->depreciation_amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line->accumulated_after,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line->net_book_value_after,2) }}</td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state title="No lines" description="This run has no depreciation lines." /></td></tr>
    @endforelse
    </tbody><tfoot><tr><th colspan="4" class="text-end">Total depreciation ({{ $currency }})</th><th class="text-end font-monospace">{{ number_format((float)$run->total_amount,2) }}</th><th colspan="2"></th></tr></tfoot></table></div>
</section>

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Workflow</span><h2>Actions</h2></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        @if($status===\App\Enums\DepreciationRunStatus::Draft) @can('depreciation.prepare')
            <form method="POST" action="{{ route('depreciation-runs.submit',$run) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Recalculate and submit this depreciation run for approval?">Recalculate and submit</button></form>
            <form method="POST" action="{{ route('depreciation-runs.discard',$run) }}" data-submit-once>@csrf<button class="btn btn-outline-danger" data-confirm="Discard this draft depreciation run? Nothing has been posted.">Discard draft</button></form>
        @endcan @endif

        @if(in_array($status,[\App\Enums\DepreciationRunStatus::Submitted,\App\Enums\DepreciationRunStatus::Approved],true))
            @if($isMaker)
                <span class="status-badge pending">{{ $status===\App\Enums\DepreciationRunStatus::Submitted ? 'Awaiting approval by another authorized user' : 'Approved by another user' }}</span>
            @elseif($canDecide)
                @if($status===\App\Enums\DepreciationRunStatus::Submitted)<form method="POST" action="{{ route('depreciation-runs.approve',$run) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Approve depreciation of {{ $currency }} {{ number_format((float)$run->total_amount,2) }}? Approval does not post it.">Approve</button></form>@endif
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal">Return to draft</button>
            @elseif($status===\App\Enums\DepreciationRunStatus::Submitted)
                <span class="status-badge pending">Awaiting authorized approver</span>
            @endif
        @endif

        @if($status===\App\Enums\DepreciationRunStatus::Approved)
            @if($user->can('depreciation.post') && $user->can('journals.post'))
                @if($policyApproved)
                    <form method="POST" action="{{ route('depreciation-runs.post',$run) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Post this depreciation run to the General Ledger? One journal dated {{ $run->accountingPeriod->end_date->format('d M Y') }} will be created.">Post to General Ledger</button></form>
                @else
                    <span class="status-badge danger">Posting blocked — depreciation policy not approved</span>
                @endif
            @else
                <span class="status-badge pending">Awaiting posting by an authorized user</span>
            @endif
        @endif

        @if($status===\App\Enums\DepreciationRunStatus::Posted) @can('depreciation.reverse')<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#reverseModal">Reverse run</button>@endcan @endif
        @if($status===\App\Enums\DepreciationRunStatus::Reversed)<span class="status-badge danger">Reversed — the period can be prepared again</span>@endif
    </div>
</section>

@if($canDecide)
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('depreciation-runs.reject',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="rejectModalTitle">Return run #{{ $run->id }} to draft</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>The run returns to draft and its assets are released. It is recalculated when submitted again.</p><label class="form-label" for="rejectReason">Reason</label><textarea class="form-control" id="rejectReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Return to draft</button></div>
</form></div></div>
@endif

@can('depreciation.reverse')
<div class="modal fade" id="reverseModal" tabindex="-1" aria-labelledby="reverseModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('depreciation-runs.reverse',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="reverseModalTitle">Reverse run #{{ $run->id }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <p>A reversing journal is posted and these lines stop counting towards accumulated depreciation. Runs for later periods must be reversed first.</p>
        <label class="form-label" for="reversalDate">Reversal date</label><input class="form-control mb-3" id="reversalDate" type="date" name="reversal_date" value="{{ old('reversal_date',$run->accountingPeriod->end_date->toDateString()) }}" required>
        <label class="form-label" for="reverseReason">Reason</label><textarea class="form-control" id="reverseReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Reverse run</button></div>
</form></div></div>
@endcan
@endsection
