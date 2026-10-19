@extends('layouts.app')
@section('title','Journal Details')
@section('page-title', $journal->displayTitle())
@section('page-description', $journal->status->label().' • '.$journal->branch->name)
@section('page-actions')
@can('journals.update') @if(in_array($journal->status,[\App\Enums\JournalStatus::Draft,\App\Enums\JournalStatus::Rejected],true))<a class="btn btn-outline-primary" href="{{ route('journals.edit',$journal) }}">Edit Draft</a>@if(blank($journal->source_type))<form class="d-flex gap-2" method="POST" action="{{ route('journals.destroy',$journal) }}" data-confirm="Discard this draft journal? It was never posted; the deletion is recorded in the audit log.">@csrf @method('DELETE')<input class="form-control" name="reason" required minlength="5" placeholder="Reason for discarding" aria-label="Reason for discarding"><button class="btn btn-outline-danger">Discard Draft</button></form>@endif @endif @endcan
@endsection
@section('content')
@if($journal->status === \App\Enums\JournalStatus::Reversed || $journal->reversalOf)
<div class="alert {{ $journal->status === \App\Enums\JournalStatus::Reversed ? 'alert-warning' : 'alert-info' }}" role="status">
    @if($journal->status === \App\Enums\JournalStatus::Reversed)<span class="status-badge danger">Reversed</span> This posted voucher has been reversed @if($journal->reversal)by <a href="{{ route('journals.show', $journal->reversal) }}">{{ $journal->reversal->displayLabel() }}</a>@endif.@endif
    @if($journal->reversalOf) {{ $journal->status === \App\Enums\JournalStatus::Posted ? 'This journal reverses' : 'This reversal request would reverse' }} <a href="{{ route('journals.show', $journal->reversalOf) }}">{{ $journal->reversalOf->displayLabel() }}</a>.@endif
</div>
@endif
@unless(in_array($journal->status,[\App\Enums\JournalStatus::Posted,\App\Enums\JournalStatus::Reversed],true)) @include('funds._restriction-warnings', ['journal' => $journal]) @endunless
<div class="surface-card mb-4"><div class="row g-3"><div class="col-md-3"><small>Date</small><strong class="d-block">{{ $journal->transaction_date->format('d M Y') }}</strong></div><div class="col-md-3"><small>Type</small><strong class="d-block">{{ $journal->voucherType->code }}</strong></div><div class="col-md-3"><small>Period</small><strong class="d-block">{{ $journal->accountingPeriod->name }}</strong></div><div class="col-md-3"><small>Reference</small><strong class="d-block">{{ $journal->reference_number ?: '—' }}</strong></div><div class="col-12"><small>Narration</small><p>{{ $journal->narration }}</p></div></div></div>
<div class="surface-card mb-4"><div class="table-responsive"><table class="table accounting-table"><thead><tr><th>#</th><th>Account</th><th>Description</th><th>Service</th><th>Fund</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>@foreach($journal->lines as $line)<tr><td>{{ $line->line_number }}</td><td>{{ $line->account->code }} — {{ $line->account->name }}</td><td>{{ $line->description ?: '—' }}</td><td>{{ $line->service?->name ?: '—' }}</td><td>{{ $line->fund?->code ?: '—' }}</td><td class="text-end font-monospace">{{ number_format((float)$line->debit,4) }}</td><td class="text-end font-monospace">{{ number_format((float)$line->credit,4) }}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="5">Totals</th><th class="text-end font-monospace">{{ number_format((float)$journal->total_debit,4) }}</th><th class="text-end font-monospace">{{ number_format((float)$journal->total_credit,4) }}</th></tr></tfoot></table></div></div>
@php($reversalSource = \App\Services\JournalReversalService::SOURCE_TYPE)
@php($threshold = number_format((float) app(\App\Services\ApprovalAuthorityService::class)->journalThreshold()->toFloat(), 2))
<div class="surface-card">
@unless($journal->source_type === $reversalSource)
<div class="d-flex flex-wrap gap-2">
@can('journals.submit') @if($journal->status===\App\Enums\JournalStatus::Draft)<form method="POST" action="{{ route('journals.submit',$journal) }}">@csrf<button class="btn btn-primary">Submit</button></form>@endif @endcan
@can('journals.approve') @if($canApproveJournal)<form method="POST" action="{{ route('journals.approve',$journal) }}">@csrf<button class="btn btn-primary">Approve</button></form>@endif @endcan
@can('journals.reject') @if(in_array($journal->status,[\App\Enums\JournalStatus::Submitted,\App\Enums\JournalStatus::Approved],true))<form class="d-flex gap-2" method="POST" action="{{ route('journals.reject',$journal) }}">@csrf<input class="form-control" name="reason" required minlength="5" placeholder="Return reason"><button class="btn btn-outline-danger">Return</button></form>@endif @endcan
@can('journals.post') @if($canPostJournal)<form method="POST" action="{{ route('journals.post',$journal) }}" data-submit-once onsubmit="return confirm('Post this balanced journal? This cannot be edited afterwards.')">@csrf<button class="btn btn-primary">Post Journal</button></form>@endif @endcan
</div>
@if($journal->status === \App\Enums\JournalStatus::Approved && ! $canPostJournal)<p class="small text-muted mt-2 mb-0">Posting needs a third user with posting permission for this branch, who neither prepared nor approved this journal, while the approval is still valid.</p>@endif
@if($journal->status === \App\Enums\JournalStatus::Submitted && ! $canApproveJournal)<p class="small text-muted mt-2 mb-0">@if($reversal['needsDirector'])Above PKR {{ $threshold }}: only a Director with authority for this branch, who did not prepare the journal, can approve it.@else Awaiting approval by an authorized user who did not prepare this journal.@endif</p>@endif
@endunless

@if($reversal['isRequest'])
    <h3 class="section-title">Reversal request</h3>
    <p class="mb-2">Requested by <strong>{{ $journal->creator?->name }}</strong> on {{ $journal->submitted_at?->format('d M Y H:i') }} to reverse <a href="{{ route('journals.show', $journal->reversalOf) }}">{{ $journal->reversalOf->displayLabel() }}</a> (PKR {{ number_format((float) $journal->reversalOf->total_debit, 2) }}).<span class="d-block"><strong>Reason:</strong> {{ $journal->reversal_reason }}</span>@if($journal->approver)<span class="d-block">Approved by <strong>{{ $journal->approver->name }}</strong>; it now needs posting by a third user.</span>@endif</p>
    <p class="small text-muted">{{ $reversal['needsDirector'] ? 'Above PKR '.$threshold.': approval needs a Director with authority for this branch.' : 'Approval needs an authorized approver.' }} The requester, the approver and the posting user must be three different people, and the preparer of the original journal cannot approve. The original journal stays posted until this reversal is posted.</p>
    <div class="d-flex flex-wrap gap-2">
        @if($reversal['canApprove'])<form method="POST" action="{{ route('journals.reversal.approve', $journal) }}" data-submit-once>@csrf<button class="btn btn-primary">Approve Reversal</button></form>@endif
        @if($reversal['canPost'])<form method="POST" action="{{ route('journals.reversal.post', $journal) }}" data-submit-once onsubmit="return confirm('Post this reversing journal? The original journal becomes reversed.')">@csrf<button class="btn btn-primary">Post Reversal</button></form>@endif
        @if($reversal['canReturn'])<form class="d-flex gap-2" method="POST" action="{{ route('journals.reversal.return', $journal) }}">@csrf<input class="form-control" name="reason" required minlength="10" placeholder="Return reason" aria-label="Return reason"><button class="btn btn-outline-danger">Return Request</button></form>@endif
        @if($reversal['canCancel'])<form class="d-flex gap-2" method="POST" action="{{ route('journals.reversal.cancel', $journal) }}">@csrf<input class="form-control" name="reason" required minlength="10" placeholder="Cancellation reason" aria-label="Cancellation reason"><button class="btn btn-outline-secondary">Cancel Request</button></form>@endif
    </div>
@elseif($reversal['pending'])
    <div class="alert alert-info mt-3 mb-0" role="status">Reversal requested: <a href="{{ route('journals.show', $reversal['pending']) }}">{{ $reversal['pending']->displayLabel() }}</a> ({{ $reversal['pending']->status->label() }}). This journal stays posted until the reversal is approved and posted.</div>
@elseif($reversal['canRequest'])
    <hr><h3 class="section-title">Request reversal</h3>
    <p class="small text-muted">A request posts nothing. Another user must approve it{{ $reversal['needsDirector'] ? ' (a Director, as the amount is above PKR '.$threshold.')' : '' }} and a third user must post it. The reversal date cannot be before {{ $journal->transaction_date->format('d M Y') }}.</p>
    <form class="row g-2" method="POST" action="{{ route('journals.reverse',$journal) }}" data-submit-once>@csrf<div class="col-md-3"><input type="date" class="form-control" name="transaction_date" min="{{ $journal->transaction_date->toDateString() }}" required aria-label="Reversal date"></div><div class="col-md-4"><select class="form-select" name="accounting_period_id" required aria-label="Accounting period">@foreach($openPeriods as $period)<option value="{{ $period->id }}">{{ $period->fiscalYear->name }} — {{ $period->name }}</option>@endforeach</select></div><div class="col-md-3"><input class="form-control" name="reason" required minlength="10" placeholder="Reversal reason" aria-label="Reversal reason"></div><div class="col-md-2"><button class="btn btn-outline-danger w-100">Request Reversal</button></div></form>
@elseif($reversal['blockedReason'] && auth()->user()->can('journals.reverse') && $journal->status === \App\Enums\JournalStatus::Posted)
    <p class="small text-muted mt-3 mb-0">{{ $reversal['blockedReason'] }}</p>
@endif
</div>
@endsection
