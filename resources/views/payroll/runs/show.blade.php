@extends('layouts.app') @section('title','Payroll Run #'.$run->id) @section('eyebrow','Payroll') @section('page-title','Payroll '.$run->period->name)
@section('page-description',$run->branch->name.' • Run #'.$run->id.' • '.$run->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @if($sensitive)<a class="btn btn-outline-primary" href="{{ route('payroll-runs.register',$run) }}">Payroll register</a>@endif
    @if($payslipsAvailable)<a class="btn btn-outline-primary" href="{{ route('payroll-runs.payslips',$run) }}">Print all payslips</a>@endif
    <a class="btn btn-outline-primary" href="{{ route('payroll-runs.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $status = $run->status;
    $user = auth()->user();
    $editable = $status->isEditable();
    $canManage = $user->can('payroll.manage');
    $errorLines = $run->lines->filter(fn ($line) => !empty($line->validation_errors))->count();
    $paymentBadges = ['posted' => 'success', 'rejected' => 'danger', 'reversed' => 'danger', 'draft' => 'neutral'];
@endphp
@include('payroll._policy-banner')
@foreach(['status','approval','policy','mapping','accounting_period_id','reason','date','employee_id','amount','type','financial_account_id','transaction_date','lines'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

@if($sensitive)
<div class="kpi-grid">
    <x-kpi-card label="Gross" value="{{ number_format((float)$run->gross_amount,2) }}" icon="Σ" tone="navy" status="{{ $status->label() }}" description="Sum of all earnings on {{ $run->lines->count() }} lines"/>
    <x-kpi-card label="Deductions" value="{{ number_format((float)$run->total_deductions,2) }}" icon="D" tone="rose" status="Entered or imported" description="Sum of all deduction components"/>
    <x-kpi-card label="Net payable" value="{{ number_format((float)$run->net_amount,2) }}" icon="N" tone="teal" status="Gross less deductions" description="Credited to Salary Payable on posting"/>
    @if($paidAmount !== null)
    <x-kpi-card label="Paid" value="{{ number_format((float)$paidAmount,2) }}" icon="P" tone="amber" status="Posted payments" description="Not yet covered by a payment: {{ number_format((float)$outstandingAmount,2) }}"/>
    @endif
</div>
@endif

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $status->isPosted() ? 'Posted — immutable, corrected only by reversal' : 'Draft workflow — no General Ledger posting yet' }}</span><h2>Run details</h2></div><span class="status-badge {{ $status->badge() }}">{{ $status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>Period</small><strong class="d-block">{{ $run->period->name }}</strong></div>
        <div class="col-md-3"><small>Period dates</small><strong class="d-block">{{ $run->period->start_date->format('d M Y') }} – {{ $run->period->end_date->format('d M Y') }}</strong></div>
        <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $run->branch->name }}</strong></div>
        <div class="col-md-3"><small>Source</small><strong class="d-block">{{ $run->source === 'import' ? 'Spreadsheet import' : 'Salary structures' }}</strong></div>
        <div class="col-md-3"><small>Prepared by</small><strong class="d-block">{{ $run->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approved by</small><strong class="d-block">{{ $run->approver?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Approval date</small><strong class="d-block">{{ $run->approved_at?->format('d M Y H:i') ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Accrual voucher</small><strong class="d-block">@if($run->journalEntry) @can('journals.view')<a href="{{ route('journals.show',$run->journalEntry) }}">{{ $run->journalEntry->voucher_number }}</a>@else {{ $run->journalEntry->voucher_number }} @endcan @else — @endif</strong></div>
        @if($run->rejection_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Returned / discarded:</strong> {{ $run->rejection_reason }}</div></div>@endif
        @if($run->reversal_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Reversed:</strong> {{ $run->reversal_reason }}</div></div>@endif
    </div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">{{ $sensitive ? 'Gross = Σ earnings · Net = gross − deductions' : 'Amounts hidden — sensitive payroll permission required' }}</span><h2>Payroll lines</h2></div>@if($errorLines > 0)<span class="status-badge danger">{{ $errorLines }} lines with errors</span>@else<span class="status-badge neutral">{{ $run->lines->count() }} employees</span>@endif</div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Code</th><th>Employee</th>
        @if($sensitive) @foreach($components as $component)<th class="text-end">{{ $component->name }}<span class="table-subtext">{{ $component->type->label() }}</span></th>@endforeach <th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th>@endif
        <th>Validation</th>@if(($editable && $canManage) || $payslipsAvailable)<th></th>@endif</tr></thead><tbody>
    @forelse($run->lines as $line)
        <tr>
            <td><code>{{ $line->employee->employee_code }}</code></td>
            <td>{{ $line->employee->name }}@if($line->employee->designation)<span class="table-subtext">{{ $line->employee->designation }}</span>@endif</td>
            @if($sensitive)
                @php
                    $itemsByComponent = $line->items->keyBy('payroll_component_id');
                @endphp
                @foreach($components as $component)<td class="text-end font-monospace">{{ $itemsByComponent->has($component->id) ? number_format((float)$itemsByComponent->get($component->id)->amount,2) : '—' }}</td>@endforeach
                <td class="text-end font-monospace">{{ number_format((float)$line->gross_amount,2) }}</td>
                <td class="text-end font-monospace">{{ number_format((float)$line->total_deductions,2) }}</td>
                <td class="text-end font-monospace"><strong>{{ number_format((float)$line->net_amount,2) }}</strong></td>
            @endif
            <td>@if(!empty($line->validation_errors)) @foreach($line->validation_errors as $error)<span class="status-badge danger d-block mb-1">{{ $error }}</span>@endforeach @elseif($status === \App\Enums\PayrollRunStatus::Draft || $status === \App\Enums\PayrollRunStatus::Rejected)<span class="status-badge neutral">Not validated</span>@else<span class="status-badge success">OK</span>@endif</td>
            @if($payslipsAvailable)
            <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-runs.payslip',[$run,$line]) }}">Payslip</a></td>
            @endif
            @if($editable && $canManage)
            <td class="text-nowrap">
                @if($sensitive)<a class="btn btn-sm btn-outline-primary" href="{{ route('payroll-runs.lines.edit',[$run,$line]) }}">Edit</a>@endif
                <form class="d-inline" method="POST" action="{{ route('payroll-runs.lines.destroy',[$run,$line]) }}" data-submit-once>@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" data-confirm="Remove this employee from the draft payroll run?">Remove</button></form>
            </td>
            @endif
        </tr>
    @empty
        <tr><td colspan="{{ ($sensitive ? $components->count() + 6 : 3) + 1 }}"><x-empty-state title="No payroll lines" description="Add employees to this draft run." /></td></tr>
    @endforelse
    </tbody>
    @if($sensitive && $run->lines->isNotEmpty())
    <tfoot><tr><th colspan="2" class="text-end">Totals</th>@foreach($components as $component)<th class="text-end font-monospace">{{ number_format((float)$componentTotals[$component->id],2) }}</th>@endforeach<th class="text-end font-monospace">{{ number_format((float)$run->gross_amount,2) }}</th><th class="text-end font-monospace">{{ number_format((float)$run->total_deductions,2) }}</th><th class="text-end font-monospace">{{ number_format((float)$run->net_amount,2) }}</th><th colspan="2"></th></tr></tfoot>
    @endif
    </table></div>
    @if($editable && $canManage && $availableEmployees->isNotEmpty())
    <form class="filter-bar mt-3" method="POST" action="{{ route('payroll-runs.lines.store',$run) }}" data-submit-once>@csrf
        <select class="form-select" name="employee_id" required aria-label="Employee to add"><option value="">Add an active employee of this branch…</option>@foreach($availableEmployees as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->employee_code }} — {{ $candidate->name }}</option>@endforeach</select>
        <button class="btn btn-outline-primary">Add line</button>
    </form>
    @endif
</section>

@if($dueRecoveries->isNotEmpty())
@php
    $lineEmployees = $run->lines->pluck('employee_id')->all();
    $lineItems = $run->lines->keyBy('employee_id');
@endphp
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Staff advances — unrecovered instalments due by {{ $run->period->name }}</span><h2>Due advance recoveries</h2></div><span class="status-badge pending">{{ $dueRecoveries->count() }} due</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Employee</th><th>Advances</th><th>Recovery component</th><th class="text-end">Due</th><th class="text-end">On this run</th></tr></thead><tbody>
    @foreach($dueRecoveries as $due)
        @php
            $onRun = $lineItems->get($due['employee']->id)?->items->firstWhere('payroll_component_id', $due['component_id'])?->amount;
        @endphp
        <tr>
            <td><code>{{ $due['employee']->employee_code }}</code> {{ $due['employee']->name }}@unless(in_array($due['employee']->id, $lineEmployees, true))<span class="table-subtext">Not on this run</span>@endunless</td>
            <td>{{ implode(', ', $due['advances']) }}</td>
            <td>{{ $due['component_name'] }}</td>
            <td class="text-end font-monospace">{{ number_format((float) $due['due'], 2) }}</td>
            <td class="text-end font-monospace">{{ $onRun !== null ? number_format((float) $onRun, 2) : '—' }}</td>
        </tr>
    @endforeach
    </tbody></table></div>
    <p class="card-copy">Recovery is recorded only when this run is posted. Amounts can be adjusted on each line before validation.</p>
    @if($editable && $canManage)
    <form method="POST" action="{{ route('payroll-runs.advance-recoveries',$run) }}" data-submit-once>@csrf<button class="btn btn-outline-primary" data-confirm="Set the recovery component of each listed employee on this draft to the amount due?">Enter due recoveries on the lines</button></form>
    @endif
</section>
@endif

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Workflow</span><h2>Actions</h2></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        @if($editable && $canManage)
            <form method="POST" action="{{ route('payroll-runs.validate',$run) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Recalculate every line and validate this payroll run?">Validate</button></form>
        @endif
        @if($status === \App\Enums\PayrollRunStatus::Validated)
            @can('payroll.submit')<form method="POST" action="{{ route('payroll-runs.submit',$run) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Submit this payroll run for approval? It can no longer be edited unless it is returned.">Submit for approval</button></form>@endcan
            @if($canManage)<form method="POST" action="{{ route('payroll-runs.revert',$run) }}" data-submit-once>@csrf<button class="btn btn-outline-primary" data-confirm="Return this run to draft to edit its lines?">Return to draft</button></form>@endif
        @endif
        @if(in_array($status,[\App\Enums\PayrollRunStatus::Draft,\App\Enums\PayrollRunStatus::Validated],true) && $canManage)
            <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#discardModal">Discard run</button>
        @endif

        @if($status === \App\Enums\PayrollRunStatus::Submitted)
            @if($isMaker)
                <span class="status-badge pending">Awaiting approval by another authorized user</span>
            @elseif($canApprove)
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#approveModal">Approve</button>
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal">Return for correction</button>
            @else
                <span class="status-badge pending">Awaiting authorized approver</span>
            @endif
        @endif

        @if($status === \App\Enums\PayrollRunStatus::Approved)
            @if($user->can('payroll.post') && $user->can('journals.post'))
                @if($policyApproved)
                    <form method="POST" action="{{ route('payroll-runs.post',$run) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Post the salary accrual to the General Ledger? This cannot be edited afterwards, only reversed.">Post accrual</button></form>
                @else
                    <span class="status-badge pending">Posting blocked — payroll policy not approved</span>
                @endif
            @else
                <span class="status-badge pending">Approved — awaiting posting by an authorized user</span>
            @endif
        @endif

        @if($status === \App\Enums\PayrollRunStatus::Posted)
            @can('payroll.pay')<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#payModal">Create salary payment</button>@endcan
            @can('payroll.post')<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#reverseModal">Reverse accrual</button>@endcan
        @endif
        @if($status === \App\Enums\PayrollRunStatus::Paid)<span class="status-badge success">Fully paid — posted payments equal the net amount</span>@endif
        @if(in_array($status,[\App\Enums\PayrollRunStatus::Rejected,\App\Enums\PayrollRunStatus::Reversed],true))<span class="status-badge neutral">{{ $status === \App\Enums\PayrollRunStatus::Reversed ? 'Reversed — a new run may be prepared for this period' : 'Returned — this run no longer blocks the period' }}</span>@endif
    </div>
</section>

@if($status->isPosted())
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Paid only through the payment workflow — debit Salary Payable, credit cash/bank</span><h2>Salary payments</h2></div><span class="status-badge neutral">{{ $run->payments->count() }} payments</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Payment</th><th>Date</th><th>Type</th>@if($sensitive)<th>Paid from</th><th class="text-end">Amount</th>@endif<th>Status</th><th></th></tr></thead><tbody>
    @forelse($run->payments as $payment)
        <tr>
            <td><code>#{{ $payment->operational_transaction_id }}</code>@if($payment->transaction->reference_number)<span class="table-subtext">Ref {{ $payment->transaction->reference_number }}</span>@endif</td>
            <td>{{ $payment->transaction->transaction_date->format('d M Y') }}</td>
            <td>{{ $payment->transaction->type->label() }}</td>
            @if($sensitive)
            <td>{{ $payment->transaction->financialAccount?->name ?? '—' }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$payment->transaction->amount,2) }}</td>
            @endif
            <td><span class="status-badge {{ $paymentBadges[$payment->transaction->status->value] ?? 'pending' }}">{{ ucfirst($payment->transaction->status->value) }}</span></td>
            <td>@can('payments.view')<a class="btn btn-sm btn-outline-primary" href="{{ route('transactions.show',$payment->operational_transaction_id) }}">Open in payments</a>@endcan</td>
        </tr>
    @empty
        <tr><td colspan="{{ $sensitive ? 7 : 5 }}"><x-empty-state title="No salary payments yet" description="Create a salary payment; it then follows the normal submit, approval, disbursement and posting steps of the payment workflow." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endif

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Append-only record</span><h2>Workflow history</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>When</th><th>Action</th><th>By</th><th>Status change</th>@if($sensitive)<th class="text-end">Net at the time</th>@endif<th>Comments</th></tr></thead><tbody>
    @foreach($run->history as $entry)
        <tr><td>{{ $entry->created_at?->format('d M Y H:i') }}</td><td><strong>{{ ucfirst(str_replace('_',' ',$entry->action)) }}</strong></td><td>{{ $entry->actor?->name ?? 'System' }}</td><td>{{ $entry->from_status ? ucfirst($entry->from_status).' → ' : '' }}{{ ucfirst($entry->to_status) }}</td>@if($sensitive)<td class="text-end font-monospace">{{ isset($entry->totals['net_amount']) ? number_format((float)$entry->totals['net_amount'],2) : '—' }}</td>@endif<td>{{ $entry->comments ?: '—' }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>

@if(in_array($status,[\App\Enums\PayrollRunStatus::Draft,\App\Enums\PayrollRunStatus::Validated],true) && $canManage)
<div class="modal fade" id="discardModal" tabindex="-1" aria-labelledby="discardModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('payroll-runs.discard',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="discardModalTitle">Discard payroll run #{{ $run->id }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>The run stays on record as returned and no longer blocks this branch and period.</p><label class="form-label" for="discardReason">Reason</label><textarea class="form-control" id="discardReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Keep run</button><button class="btn btn-outline-danger">Discard run</button></div>
</form></div></div>
@endif

@if($canApprove)
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('payroll-runs.approve',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="approveModalTitle">Approve payroll {{ $run->period->name }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Approve this payroll run for {{ $run->branch->name }}@if($sensitive) with a net payable of {{ number_format((float)$run->net_amount,2) }}@endif? Approval does not post to the General Ledger and pays nobody.</p><label class="form-label" for="approveComments">Comments (optional)</label><textarea class="form-control" id="approveComments" name="comments" rows="2" maxlength="2000"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Approve payroll run</button></div>
</form></div></div>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('payroll-runs.reject',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="rejectModalTitle">Return payroll run #{{ $run->id }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label" for="rejectReason">Reason</label><textarea class="form-control" id="rejectReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Return for correction</button></div>
</form></div></div>
@endif

@if($status === \App\Enums\PayrollRunStatus::Posted)
@can('payroll.pay')
<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('payroll-runs.pay',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="payModalTitle">Create salary payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <p>Creates a draft payment in the payment workflow that debits Salary Payable. It is not approved, disbursed or posted here: the normal approval threshold and Director authority apply.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="payType">Payment type</label><select class="form-select" id="payType" name="type" required><option value="bank_payment" @selected(old('type')==='bank_payment')>Bank payment</option><option value="cash_payment" @selected(old('type')==='cash_payment')>Cash payment</option></select></div>
            <div class="col-md-6"><label class="form-label" for="payAccount">Paid from</label><select class="form-select" id="payAccount" name="financial_account_id" required><option value="">Select account</option>@foreach($financialAccounts as $account)<option value="{{ $account->id }}" @selected(old('financial_account_id')==$account->id)>{{ $account->name }} ({{ $account->type }})</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label" for="payAmount">Amount</label><input class="form-control" id="payAmount" name="amount" inputmode="decimal" value="{{ old('amount', $sensitive ? $outstandingAmount : '') }}" required></div>
            <div class="col-md-6"><label class="form-label" for="payDate">Payment date</label><input class="form-control" id="payDate" type="date" name="transaction_date" value="{{ old('transaction_date', today()->toDateString()) }}" required></div>
            <div class="col-12"><label class="form-label" for="payReference">Reference (optional)</label><input class="form-control" id="payReference" name="reference_number" maxlength="100" value="{{ old('reference_number') }}"></div>
        </div>
        @if($sensitive)<p class="card-copy mt-3 mb-0">Unpaid net salary not yet covered by a payment: {{ number_format((float)$outstandingAmount,2) }}. A payment can never exceed it.</p>@endif
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create draft payment</button></div>
</form></div></div>
@endcan
@can('payroll.post')
<div class="modal fade" id="reverseModal" tabindex="-1" aria-labelledby="reverseModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('payroll-runs.reverse',$run) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="reverseModalTitle">Reverse payroll accrual</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Posts a reversing voucher. Not possible while a salary payment is linked that has not been rejected or reversed.</p>
        <label class="form-label" for="reverseDate">Reversal date (in an open period)</label><input class="form-control mb-3" id="reverseDate" type="date" name="date" value="{{ old('date', $run->period->end_date->toDateString()) }}" required>
        <label class="form-label" for="reverseReason">Reason</label><textarea class="form-control" id="reverseReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-outline-danger">Reverse accrual</button></div>
</form></div></div>
@endcan
@endif
@endsection
