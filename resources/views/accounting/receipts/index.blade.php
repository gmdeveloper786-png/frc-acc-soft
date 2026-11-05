@extends('layouts.app')
@section('title', 'Receipts')
@section('page-title', 'Receipts')
@section('page-description', 'Unified register of all incoming Cash and Bank receipts across accessible branches, with voucher history and posting workflows.')
@section('page-actions')
    @if($canCreate)
        <a class="btn btn-primary" href="{{ route('receipts.create') }}">Create Receipt</a>
    @endif
@endsection

@section('content')
<div class="card">
    <div class="card-body">
        <form class="row g-3 mb-4" method="GET" action="{{ route('receipts.index') }}">
            <div class="col-md-3">
                <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Reference, voucher, or party" aria-label="Search">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="branch_id" aria-label="Branch">
                    <option value="">All accessible branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="type" aria-label="Receipt type">
                    <option value="">All Receipt Types</option>
                    <option value="cash_receipt" @selected(in_array(request('type'), ['cash', 'cash_receipt'], true))>Cash Receipt</option>
                    <option value="bank_receipt" @selected(in_array(request('type'), ['bank', 'bank_receipt'], true))>Bank Receipt</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status" aria-label="Status">
                    <option value="">All statuses</option>
                    @foreach(\App\Enums\OperationalTransactionStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ ucfirst($status->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <input class="form-control" type="date" name="from" value="{{ request('from') }}" aria-label="From date">
                <input class="form-control" type="date" name="to" value="{{ request('to') }}" aria-label="To date">
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <button class="btn btn-primary" type="submit">Filter</button>
                <a class="btn btn-outline-secondary" href="{{ route('receipts.index') }}">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Voucher / Ref</th>
                        <th>Method</th>
                        <th>Receiving Branch</th>
                        <th>Receiving Account</th>
                        <th>Received From</th>
                        <th>Credit Ledger / Receipt Against</th>
                        <th class="text-end">Amount Received (PKR)</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($receipts as $receipt)
                    @php
                        $isCash = $receipt->type === \App\Enums\OperationalTransactionType::CashReceipt;
                        $party = $receipt->party_name ?: ($receipt->vendor?->name ?? '—');
                    @endphp
                    <tr data-receipt-row="{{ $receipt->id }}">
                        <td>{{ $receipt->transaction_date->format('d M Y') }}</td>
                        <td>
                            @if($receipt->journalEntry?->voucher_number)
                                <strong>{{ $receipt->journalEntry->voucher_number }}</strong><br>
                            @endif
                            <small class="text-muted">{{ $receipt->reference_number ?: 'No reference' }}</small>
                        </td>
                        <td>
                            @if($isCash)
                                <span class="badge text-bg-success">Cash</span>
                            @else
                                <span class="badge text-bg-primary">Bank</span>
                            @endif
                        </td>
                        <td>{{ $receipt->branch->name }}</td>
                        <td>{{ $receipt->financialAccount?->name ?? '—' }}</td>
                        <td>{{ $party }}</td>
                        <td>
                            @if($receipt->contraAccount)
                                <small class="text-muted">{{ $receipt->contraAccount->code }}</small><br>
                                {{ $receipt->contraAccount->name }}
                            @else
                                <span class="text-muted">System Default</span>
                            @endif
                        </td>
                        <td class="text-end font-monospace"><strong>{{ number_format((float) $receipt->amount, 2) }}</strong></td>
                        <td>
                            @php
                                $badgeClass = match($receipt->status->value) {
                                    'posted' => 'text-bg-success',
                                    'draft' => 'text-bg-secondary',
                                    'reversed' => 'text-bg-danger',
                                    'submitted' => 'text-bg-info',
                                    'approved' => 'text-bg-warning',
                                    default => 'text-bg-light',
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ ucfirst($receipt->status->value) }}</span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('transactions.show', $receipt) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">No receipts found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $receipts->links() }}
    </div>
</div>
@endsection
