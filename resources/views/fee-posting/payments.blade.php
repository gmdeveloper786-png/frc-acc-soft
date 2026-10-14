@extends('layouts.app') @section('title','Fee Posting Preview') @section('page-title','FRC Fee Posting — Preview')
@section('content')
@include('fee-posting.partials.banners')
<div class="card mb-3"><div class="card-body">
    <form class="row g-2" method="GET">
        <div class="col-md-3"><label class="form-label">Branch</label><select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Service</label><select class="form-select" name="service_id"><option value="">All services</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(request('service_id') == $service->id)>{{ $service->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">Method</label><select class="form-select" name="payment_method"><option value="">All methods</option>@foreach($methods as $key => $label)<option value="{{ $key }}" @selected(request('payment_method') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">From</label><input class="form-control" type="date" name="date_from" value="{{ request('date_from') }}"></div>
        <div class="col-md-2"><label class="form-label">To</label><input class="form-control" type="date" name="date_to" value="{{ request('date_to') }}"></div>
        <div class="col-12"><button class="btn btn-primary">Preview</button> <span class="text-muted small ms-2">The preview writes nothing. Payments already posted are not listed; see Posted Fee Receipts.</span></div>
    </form>
</div></div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Eligible on this page</div><div class="h5 mb-0">{{ $totals['eligible_count'] }} · PKR {{ number_format((float) $totals['eligible_amount'], 2) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Skipped on this page</div><div class="h5 mb-0">{{ $totals['skipped_count'] }} · PKR {{ number_format((float) $totals['skipped_amount'], 2) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Journal debits</div><div class="h5 mb-0">PKR {{ number_format((float) $totals['debit'], 2) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Journal credits</div><div class="h5 mb-0">PKR {{ number_format((float) $totals['credit'], 2) }}</div></div></div></div>
</div>

<form method="POST" action="{{ route('fee-posting.payments.post') }}">@csrf
<div class="card"><div class="card-body">
    <div class="table-responsive"><table class="table align-top">
        <thead><tr><th></th><th>Date</th><th>Receipt</th><th>Branch</th><th>Service</th><th>Method</th><th>Source status</th><th class="text-end">Amount</th><th>Journal that would be created / reason skipped</th></tr></thead>
        <tbody>
        @forelse($records as $payment)
            @php($plan = $plans[$payment->id])
            <tr>
                <td>@if($plan->eligible() && $canPost)<input class="form-check-input" type="checkbox" name="ids[]" value="{{ $payment->id }}" aria-label="Select payment {{ $payment->receipt_number }}">@endif</td>
                <td>{{ $payment->payment_date?->format('d M Y') }}</td>
                <td>{{ $payment->receipt_number ?? '—' }}<div class="text-muted small">FRC #{{ $payment->frc_payment_id }}</div></td>
                <td>{{ $payment->branch?->name ?? 'Unmapped' }}</td>
                <td>{{ $payment->service?->name ?? 'Unmapped' }}</td>
                <td>{{ $methods[$payment->payment_method] ?? $payment->payment_method }}</td>
                <td>{{ $payment->source_status }}@if($payment->posting_status !== 'unposted')<div class="small text-muted">{{ $payment->posting_status }}</div>@endif</td>
                <td class="text-end">{{ number_format((float) $payment->amount, 2) }}</td>
                <td>
                    @if($plan->eligible())
                        <div class="small text-muted">{{ $plan->voucherCode }} · {{ $plan->date }} · {{ $plan->basis }} basis · key {{ $plan->sourceKey }}</div>
                        <table class="table table-sm mb-0"><thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>
                        @foreach($plan->lines as $line)<tr><td>{{ $line['account_code'] }} — {{ $line['account_name'] }}</td><td class="text-end">{{ $line['debit'] !== '0.0000' ? number_format((float) $line['debit'], 2) : '' }}</td><td class="text-end">{{ $line['credit'] !== '0.0000' ? number_format((float) $line['credit'], 2) : '' }}</td></tr>@endforeach
                        </tbody></table>
                    @else
                        <span class="status-badge pending">Skipped</span>
                        <ul class="small mb-0">@foreach($plan->reasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center py-5">No unposted FRC payments match these filters. Payments appear here after they are synchronised from the FRC software.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $records->links() }}
    @if($canPost && $totals['eligible_count'] > 0)
        <button class="btn btn-primary" data-confirm="Post the selected payments? Each creates its own Fee Receipt (FR) journal. Posted journals can only be corrected by reversal.">Post selected payments</button>
    @endif
</div></div>
</form>
@endsection
