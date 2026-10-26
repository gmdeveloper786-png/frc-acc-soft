@extends('layouts.app') @section('title','Staff Advance '.$advance->reference) @section('eyebrow','Payroll') @section('page-title',$advance->type->label().' '.$advance->reference)
@section('page-description',$advance->employee->name.' • '.$advance->branch->name.' • '.$advance->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @if($sensitive && $advance->status->isEditable())@can('staff_advances.manage')<a class="btn btn-outline-primary" href="{{ route('staff-advances.edit',$advance) }}">Edit draft</a>@endcan @endif
    <a class="btn btn-outline-primary" href="{{ route('staff-advances.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $status = $advance->status;
    $user = auth()->user();
    $statuses = \App\Enums\StaffAdvanceStatus::class;
    $isMaker = in_array($user->id, [$advance->created_by, $advance->submitted_by], true);
    $paymentBadges = ['posted' => 'success', 'rejected' => 'danger', 'reversed' => 'danger', 'draft' => 'neutral'];
@endphp
@include('payroll._policy-banner')
@foreach(['status','approval','reason','operational_transaction_id','payroll_component_id','first_recovery_period_id'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

@if($sensitive && $advance->relationLoaded('instalments'))
<div class="kpi-grid">
    <x-kpi-card label="Principal" value="{{ number_format((float)$advance->principal_amount,2) }}" icon="P" tone="navy" status="{{ $status->label() }}" description="{{ $advance->instalments->count() }} scheduled instalments"/>
    <x-kpi-card label="Recovered" value="{{ number_format((float)$advance->recoveredAmount(),2) }}" icon="R" tone="teal" status="Posted payroll runs" description="Recovery component deducted on posted runs"/>
    <x-kpi-card label="Outstanding" value="{{ $status === $statuses::Cancelled ? '—' : number_format((float)$advance->outstandingAmount(),2) }}" icon="O" tone="amber" status="Principal less recovered" description="Settled when nothing is outstanding"/>
</div>
@endif

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Register entry — this module posts nothing</span><h2>Details</h2></div><span class="status-badge {{ $status->badge() }}">{{ $status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>Employee</small><strong class="d-block">{{ $advance->employee->name }}</strong><span class="table-subtext">{{ $advance->employee->employee_code }}@if($advance->employee->designation) · {{ $advance->employee->designation }}@endif</span></div>
        <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $advance->branch->name }}</strong></div>
        <div class="col-md-3"><small>Type</small><strong class="d-block">{{ $advance->type->label() }}</strong></div>
        <div class="col-md-3"><small>First recovery period</small><strong class="d-block">{{ $advance->firstRecoveryPeriod->name }}</strong></div>
        <div class="col-md-3"><small>Recovery component</small><strong class="d-block">{{ $advance->component->name }}</strong></div>
        @if($sensitive)<div class="col-md-3"><small>Instalments entered as</small><strong class="d-block">{{ $advance->instalment_count ? $advance->instalment_count.' instalments' : number_format((float)$advance->instalment_amount,2).' per period' }}</strong></div>@endif
        <div class="col-md-3"><small>Prepared by</small><strong class="d-block">{{ $advance->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approved by</small><strong class="d-block">{{ $advance->approver?->name ?? '—' }}</strong><span class="table-subtext">{{ $advance->approved_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-6"><small>Purpose</small><p class="mb-0">{{ $advance->purpose }}</p></div>
        <div class="col-md-6"><small>Notes</small><p class="mb-0">{{ $advance->notes ?: '—' }}</p></div>
        @if($advance->rejection_reason && $status->isEditable())<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned:</strong> {{ $advance->rejection_reason }}</div></div>@endif
        @if($advance->cancellation_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Cancelled:</strong> {{ $advance->cancellation_reason }}</div></div>@endif
    </div>
</section>

@if($sensitive && $advance->relationLoaded('instalments'))
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">One instalment per accounting period · the last absorbs rounding</span><h2>Recovery schedule</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>#</th><th>Period</th><th class="text-end">Instalment</th><th class="text-end">Recovered</th><th class="text-end">Outstanding</th><th>Recovered by</th></tr></thead><tbody>
    @foreach($advance->instalments as $instalment)
        <tr>
            <td>{{ $instalment->sequence }}</td>
            <td>{{ $instalment->period->name }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$instalment->amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$instalment->recovered_amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$instalment->outstandingAmount(),2) }}</td>
            <td>@if($instalment->payrollRunLine)<a href="{{ route('payroll-runs.show',$instalment->payrollRunLine->payroll_run_id) }}">Payroll run #{{ $instalment->payrollRunLine->payroll_run_id }}</a>@else — @endif</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot><tr><th colspan="2" class="text-end">Total</th><th class="text-end font-monospace">{{ number_format((float)$advance->principal_amount,2) }}</th><th class="text-end font-monospace">{{ number_format((float)$advance->recoveredAmount(),2) }}</th><th class="text-end font-monospace">{{ number_format((float)$advance->outstandingAmount(),2) }}</th><th></th></tr></tfoot>
    </table></div>
    <p class="card-copy">An instalment counts as recovered only when a posted payroll run deducts the recovery component for this employee. Payroll posting stays blocked until the payroll calculation policy is approved.</p>
</section>
@endif

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Paid out only through the payment workflow</span><h2>Disbursement</h2></div></div>
    @if($advance->disbursement)
        <div class="row g-3">
            <div class="col-md-3"><small>Payment</small><strong class="d-block">#{{ $advance->disbursement->id }} · {{ $advance->disbursement->type->label() }}</strong></div>
            <div class="col-md-3"><small>Date</small><strong class="d-block">{{ $advance->disbursement->transaction_date->format('d M Y') }}</strong></div>
            @if($sensitive)<div class="col-md-3"><small>Amount</small><strong class="d-block">{{ number_format((float)$advance->disbursement->amount,2) }}</strong></div>@endif
            <div class="col-md-3"><small>Status</small><span class="status-badge {{ $paymentBadges[$advance->disbursement->status->value] ?? 'pending' }}">{{ ucfirst($advance->disbursement->status->value) }}</span> @can('payments.view')<a class="ms-2" href="{{ route('transactions.show',$advance->disbursement) }}">Open in payments</a>@endcan</div>
        </div>
    @elseif($status === $statuses::Approved)
        @if($sensitive && $user->can('staff_advances.manage'))
            <p>Pay this advance from Payments for exactly <strong>{{ number_format((float)$advance->principal_amount,2) }}</strong>; it debits <strong>{{ $advance->component->account?->code }} {{ $advance->component->account?->name }}</strong> and is linked to this advance automatically when the payment is posted. A payment posted earlier can still be linked below.</p>
            @canany(['payments.create'])<div class="d-flex gap-2 mb-3"><a class="btn btn-outline-primary" href="{{ route('payments.create', ['advance' => $advance->id, 'source' => 'bank']) }}">New bank payment</a><a class="btn btn-outline-primary" href="{{ route('payments.create', ['advance' => $advance->id, 'source' => 'cash']) }}">New cash payment</a></div>@endcanany
            @if($disbursements->isNotEmpty())
            <form class="filter-bar" method="POST" action="{{ route('staff-advances.disbursement',$advance) }}" data-submit-once>@csrf
                <select class="form-select" name="operational_transaction_id" required aria-label="Posted payment"><option value="">Select the posted payment that paid this advance…</option>@foreach($disbursements as $payment)<option value="{{ $payment->id }}">#{{ $payment->id }} · {{ $payment->transaction_date->format('d M Y') }} · {{ $payment->type->label() }} · {{ $payment->financialAccount?->name }}@if($payment->reference_number) · Ref {{ $payment->reference_number }}@endif</option>@endforeach</select>
                <button class="btn btn-primary" data-confirm="Link this posted payment as the payout of the advance? The advance becomes active.">Link payment</button>
            </form>
            @else
            <x-empty-state title="No matching posted payment yet" description="Only posted cash or bank payments of this branch, for the exact principal and debiting the recovery component's ledger, can be linked." />
            @endif
        @else
            <span class="status-badge pending">Approved — awaiting payout through the payment workflow</span>
        @endif
    @else
        <x-empty-state title="Not disbursed" description="An advance is paid out after approval, through the normal payment workflow." />
    @endif
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Workflow</span><h2>Actions</h2></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        @if($status === $statuses::Draft)
            @can('staff_advances.manage')<form method="POST" action="{{ route('staff-advances.submit',$advance) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Submit this advance for approval? Its terms can no longer be edited unless it is returned.">Submit for approval</button></form>@endcan
        @endif
        @if($status === $statuses::Submitted)
            @if($canApprove)
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#approveModal">Approve</button>
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal">Return to preparer</button>
            @elseif($isMaker)
                <span class="status-badge pending">Awaiting approval by another authorized user</span>
            @else
                <span class="status-badge pending">Awaiting authorized approver</span>
            @endif
        @endif
        @if(in_array($status, [$statuses::Draft, $statuses::Submitted, $statuses::Approved], true) && $advance->disbursement_transaction_id === null && $user->canAny(['staff_advances.manage', 'staff_advances.approve']))
            <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancelModal">Cancel advance</button>
        @endif
        @if($status === $statuses::Active)<span class="status-badge success">Active — recovered through posted payroll runs</span>@endif
        @if($status === $statuses::Settled)<span class="status-badge neutral">Settled {{ $advance->settled_at?->format('d M Y') }} — fully recovered</span>@endif
        @if($status === $statuses::Cancelled)<span class="status-badge danger">Cancelled</span>@endif
    </div>
</section>

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Append-only record</span><h2>History</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>When</th><th>Action</th><th>By</th><th>Status change</th><th>Comments</th></tr></thead><tbody>
    @foreach($advance->history as $entry)
        <tr><td>{{ $entry->created_at?->format('d M Y H:i') }}</td><td><strong>{{ ucfirst(str_replace('_',' ',$entry->action)) }}</strong></td><td>{{ $entry->actor?->name ?? 'System' }}</td><td>{{ $entry->from_status ? ucfirst($entry->from_status).' → ' : '' }}{{ ucfirst($entry->to_status) }}</td><td>{{ $entry->comments ?: '—' }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>

@if($canApprove)
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('staff-advances.approve',$advance) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="approveModalTitle">Approve {{ $advance->reference }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Approve this {{ strtolower($advance->type->label()) }} of {{ number_format((float)$advance->principal_amount,2) }} for {{ $advance->employee->name }}? Approval posts nothing and pays nobody; the payout goes through the payment workflow.</p><label class="form-label" for="approveComments">Comments (optional)</label><textarea class="form-control" id="approveComments" name="comments" rows="2" maxlength="2000"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Approve</button></div>
</form></div></div>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('staff-advances.reject',$advance) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="rejectModalTitle">Return {{ $advance->reference }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label" for="rejectReason">Reason</label><textarea class="form-control" id="rejectReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Return to preparer</button></div>
</form></div></div>
@endif

@if(in_array($status, [$statuses::Draft, $statuses::Submitted, $statuses::Approved], true) && $advance->disbursement_transaction_id === null && $user->canAny(['staff_advances.manage', 'staff_advances.approve']))
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('staff-advances.cancel',$advance) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="cancelModalTitle">Cancel {{ $advance->reference }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>The advance stays on record as cancelled. Only an advance that has not been paid out can be cancelled.</p><label class="form-label" for="cancelReason">Reason</label><textarea class="form-control" id="cancelReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Keep advance</button><button class="btn btn-outline-danger">Cancel advance</button></div>
</form></div></div>
@endif
@endsection
