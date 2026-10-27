@extends('layouts.app')
@section('title', 'Purchase Bill')
@section('page-title', 'Purchase Bill '.$purchaseBill->bill_number)
@section('content')
<div class="row g-4">
    <div class="col-lg-8"><div class="card"><div class="card-body">
        <p><strong>{{ $purchaseBill->vendor->name }}</strong> - {{ $purchaseBill->branch->name }} - {{ $purchaseBill->invoice_date->format('d M Y') }}</p>
        @if($purchaseBill->purchaseOrder)
            <p>Purchase order: @can('purchase_orders.view')<a href="{{ route('purchase-orders.show', $purchaseBill->purchaseOrder) }}">{{ $purchaseBill->purchaseOrder->po_number }}</a>@else{{ $purchaseBill->purchaseOrder->po_number }}@endcan @if($purchaseBill->po_exception_status)<span class="status-badge {{ $purchaseBill->po_exception_status === 'approved' ? 'success' : 'pending' }}">PO exception {{ $purchaseBill->po_exception_status }}</span>@endif</p>
            @if($purchaseBill->po_exception_reason)<div class="alert alert-warning"><strong>Exceeds purchase order - documented exception:</strong> {{ $purchaseBill->po_exception_reason }}@if($purchaseBill->po_exception_status === 'pending') <span class="d-block">Approval requires a user authorized for PO billing exceptions.</span>@endif</div>@endif
        @endif
        @if($purchaseBill->journalEntry)
            <p><strong>{{ $purchaseBill->status->value === 'reversed' ? 'Original journal:' : 'Journal:' }}</strong> @can('journals.view')<a href="{{ route('journals.show', $purchaseBill->journalEntry) }}">{{ $purchaseBill->journalEntry->voucher_number }}</a>@else{{ $purchaseBill->journalEntry->voucher_number }}@endcan</p>
            @if($purchaseBill->status->value === 'reversed' && $purchaseBill->journalEntry->reversal)
                <p data-reversal-journal><strong>Reversal journal:</strong> @can('journals.view')<a href="{{ route('journals.show', $purchaseBill->journalEntry->reversal) }}">{{ $purchaseBill->journalEntry->reversal->voucher_number }}</a>@else{{ $purchaseBill->journalEntry->reversal->voucher_number }}@endcan</p>
            @endif
        @endif
        @error('approval')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('status')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('reason')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('accounting_period_id')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('transaction_date')<div class="alert alert-danger">{{ $message }}</div>@enderror
        <div class="table-responsive"><table class="table"><thead><tr><th>Account</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr></thead><tbody>
        @foreach($purchaseBill->lines as $line)<tr><td>{{ $line->account->code }} - {{ $line->account->name }}</td><td>{{ $line->description }}</td><td class="text-end">{{ $line->quantity }}</td><td class="text-end">{{ number_format((float) $line->unit_amount, 2) }}</td><td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td></tr>@endforeach
        </tbody><tfoot><tr><th colspan="4" class="text-end">{{ in_array($purchaseBill->status->value, ['reversed', 'cancelled'], true) ? 'Original Bill Total' : 'Total' }}</th><th class="text-end">PKR {{ number_format((float) $purchaseBill->total_amount, 2) }}</th></tr><tr><th colspan="4" class="text-end">Outstanding</th><th class="text-end" data-outstanding>PKR {{ number_format((float) $purchaseBill->outstandingAmount(), 2) }}@if($purchaseBill->status->value === 'reversed') (Reversed)@elseif($purchaseBill->status->value === 'cancelled') (Cancelled)@endif</th></tr></tfoot></table></div>
    </div></div></div>
    <div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5">Status: {{ $purchaseBill->status->value }}</h2><div class="d-grid gap-2">
        @if($purchaseBill->status->value === 'draft')
            @can('purchase_bills.update')<a class="btn btn-outline-primary w-100" href="{{ route('purchase-bills.edit', $purchaseBill) }}">Edit draft</a>@endcan
            @can('purchase_bills.submit')<form method="POST" action="{{ route('purchase-bills.submit', $purchaseBill) }}">@csrf<button class="btn btn-primary w-100">Submit</button></form>@endcan
            @if($canCancelDraft)
                <div class="border-top pt-3 mt-2">
                    <h3 class="h6 text-danger">Cancel Draft</h3>
                    <p class="small text-muted mb-2">Cancelling this draft preserves the original record and audit trail with zero liability.</p>
                    <form method="POST" action="{{ route('purchase-bills.cancel-draft', $purchaseBill) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small" for="cancel-reason">Cancellation Reason</label>
                            <textarea class="form-control form-control-sm" id="cancel-reason" name="reason" rows="2" placeholder="Mandatory cancellation reason" required minlength="5" maxlength="2000">{{ old('reason') }}</textarea>
                        </div>
                        <button class="btn btn-outline-danger w-100" data-confirm="Cancel this draft purchase bill? This action cannot be undone.">Cancel Draft</button>
                    </form>
                </div>
            @endif
        @endif
        @if($purchaseBill->status->value === 'submitted')
            @if($canApprove)
                <form method="POST" action="{{ route('purchase-bills.approve', $purchaseBill) }}">
                    @csrf
                    <button class="btn btn-success w-100" data-confirm="Approve bill?">Approve</button>
                </form>
            @endif
            @if($canReturn)
                <form method="POST" action="{{ route('purchase-bills.reject', $purchaseBill) }}">
                    @csrf
                    <textarea class="form-control mb-2" name="reason" placeholder="Rejection reason" required></textarea>
                    <button class="btn btn-outline-danger w-100">Return for correction</button>
                </form>
            @endif
            @if($isPreparer && auth()->user()->can('purchase_bills.approve'))
                <p class="text-muted small mb-0">You prepared this bill, so an independent approver must approve or return it.</p>
            @endif
        @endif
        @if($purchaseBill->status->value === 'approved')
            @can('purchase_bills.post')<form method="POST" action="{{ route('purchase-bills.post', $purchaseBill) }}">@csrf<button class="btn btn-primary w-100" data-confirm="Post payable journal?">Post</button></form>@endcan
        @endif
        @if($purchaseBill->status->value === 'posted')
            @if((float) $purchaseBill->outstandingAmount() > 0)
                {{-- Only what is not already held by a payment in approval can be paid again. --}}
                @php($availableToPay = $purchaseBill->availableToPayAmount())
                @can('vendor_payments.create')
                    @if((float) $availableToPay > 0)
                        <a class="btn btn-outline-primary" href="{{ route('payments.create', ['bill' => $purchaseBill->id]) }}">Record vendor payment</a>
                    @else
                        <p class="text-muted mb-0" data-bill-fully-reserved>The outstanding balance is already held by payments awaiting approval, disbursement or posting. A new payment can be recorded if one of them is rejected or reversed.</p>
                    @endif
                @endcan
            @endif

            @if($hasCommittedPayments)
                <div class="alert alert-warning mb-0 mt-2" data-committed-payments-warning>Active payments exist against this bill. Reverse or reject linked payments before reversing the purchase bill.</div>
            @elseif($canReverse)
                <div class="border-top pt-3 mt-3">
                    <h3 class="h6 text-danger">Reverse Purchase Bill</h3>
                    <p class="small text-muted">Reversing this bill creates a balanced reversal journal entry and restores the vendor balance. The original bill and audit trail are preserved.</p>
                    <form method="POST" action="{{ route('purchase-bills.reverse', $purchaseBill) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small" for="reversal-period">Accounting Period</label>
                            <select class="form-select form-select-sm" id="reversal-period" name="accounting_period_id" required>
                                <option value="">Select open period</option>
                                @foreach($openPeriods as $period)
                                    <option value="{{ $period->id }}" data-start-date="{{ $period->start_date->toDateString() }}" data-end-date="{{ $period->end_date->toDateString() }}" @selected(($defaultReversalPeriodId ?? null) === $period->id)>{{ $period->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small" for="reversal-date">Reversal Date</label>
                            <input class="form-control form-control-sm" id="reversal-date" type="date" name="transaction_date" value="{{ $defaultReversalDate ?? today()->toDateString() }}" required>
                            <div class="form-text small d-none" id="reversal-period-help">Select an open accounting period that contains the reversal date.</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small" for="reversal-reason">Reason for Reversal</label>
                            <textarea class="form-control form-control-sm" id="reversal-reason" name="reason" rows="2" placeholder="Mandatory reason for reversal" required minlength="5" maxlength="2000">{{ old('reason') }}</textarea>
                        </div>
                        <button class="btn btn-outline-danger w-100" data-confirm="Create and post reversing journal? This action cannot be undone.">Reverse Bill</button>
                    </form>
                </div>
            @endif
        @endif
        @if($purchaseBill->status->value === 'reversed')
            <div class="alert alert-secondary mb-0">This purchase bill has been reversed. Original record and reversal journal are preserved.</div>
        @endif
        @if($purchaseBill->status->value === 'cancelled')
            <div class="alert alert-secondary mb-0" data-cancelled-bill-alert>
                <strong>This draft purchase bill has been cancelled.</strong>
                @if($cancellation)
                    <div class="small mt-2 pt-2 border-top">
                        <strong>Reason:</strong> {{ $cancellation->properties['reason'] ?? 'Not specified' }}<br>
                        <strong>Cancelled by:</strong> {{ $cancellation->actor?->name ?? 'User #'.$cancellation->actor_id }} on {{ $cancellation->created_at->format('d M Y, h:i A') }}
                    </div>
                @endif
            </div>
        @endif
    </div></div></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const date = document.getElementById('reversal-date');
    const period = document.getElementById('reversal-period');
    const help = document.getElementById('reversal-period-help');

    if (!date || !period || !help) {
        return;
    }

    const syncPeriod = () => {
        const selected = Array.from(period.options).find((option) => option.dataset.startDate <= date.value && option.dataset.endDate >= date.value);
        period.value = selected?.value ?? '';
        help.classList.toggle('d-none', selected !== undefined);
    };

    date.addEventListener('change', syncPeriod);
    syncPeriod();
});
</script>
@endsection
