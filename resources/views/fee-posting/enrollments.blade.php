@extends('layouts.app') @section('title','Enrollment Recognition') @section('page-title','FRC Enrollment Fee Recognition (Accrual)')
@section('content')
@include('fee-posting.partials.banners')
@if(! $isAccrual)
    <div class="card"><div class="card-body text-center py-5">The fee accounting basis is <strong>{{ $feeSettings['basis'] }}</strong>. Enrollment recognition applies only under the accrual basis, so there is nothing to show here.</div></div>
@else
<div class="card mb-3"><div class="card-body">
    <form class="row g-2" method="GET">
        <div class="col-md-4"><label class="form-label">Branch</label><select class="form-select" name="branch_id"><option value="">All my branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">Service</label><select class="form-select" name="service_id"><option value="">All services</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(request('service_id') == $service->id)>{{ $service->name }}</option>@endforeach</select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary">Preview</button></div>
    </form>
    <p class="text-muted small mb-0 mt-2">Recognition posts Dr Accounts Receivable / Cr fee income for the enrollment total. A later change of the total posts an adjustment for the exact difference only. The preview writes nothing.</p>
</div></div>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">To recognise on this page</div><div class="h5 mb-0">{{ $totals['eligible_count'] }} · PKR {{ number_format((float) $totals['eligible_amount'], 2) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Skipped on this page</div><div class="h5 mb-0">{{ $totals['skipped_count'] }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Journal debits / credits</div><div class="h5 mb-0">{{ number_format((float) $totals['debit'], 2) }} / {{ number_format((float) $totals['credit'], 2) }}</div></div></div></div>
</div>
<form method="POST" action="{{ route('fee-posting.enrollments.post') }}">@csrf
<div class="card"><div class="card-body">
    <div class="table-responsive"><table class="table align-top">
        <thead><tr><th></th><th>FRC enrollment</th><th>Branch</th><th>Service</th><th>Status</th><th class="text-end">Final total</th><th>Journal that would be created / reason skipped</th></tr></thead>
        <tbody>
        @forelse($records as $enrollment)
            @php($plan = $plans[$enrollment->id])
            <tr>
                <td>@if($plan->eligible() && $canPost)<input class="form-check-input" type="checkbox" name="ids[]" value="{{ $enrollment->id }}" aria-label="Select enrollment {{ $enrollment->frc_enrollment_id }}">@endif</td>
                <td>#{{ $enrollment->frc_enrollment_id }}</td>
                <td>{{ $enrollment->branch?->name ?? 'Unmapped' }}</td>
                <td>{{ $enrollment->service?->name ?? 'Unmapped' }}</td>
                <td>{{ $enrollment->source_status }}</td>
                <td class="text-end">{{ number_format((float) $enrollment->final_total, 2) }}</td>
                <td>
                    @if($plan->eligible())
                        <div class="small text-muted">{{ $plan->voucherCode }} · {{ $plan->date }} · key {{ $plan->sourceKey }}</div>
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
            <tr><td colspan="7" class="text-center py-5">No approved, active or completed FRC enrollments match these filters.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $records->links() }}
    @if($canPost && $totals['eligible_count'] > 0)
        <button class="btn btn-primary" data-confirm="Recognise the selected enrollments? Each creates its own Journal Voucher (JV).">Recognise selected enrollments</button>
    @endif
</div></div>
</form>
@endif
@endsection
