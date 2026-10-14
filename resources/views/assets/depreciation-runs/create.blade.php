@extends('layouts.app') @section('title','New Depreciation Run') @section('eyebrow','Fixed Assets') @section('page-title','New Depreciation Run')
@section('page-description','Choose a branch and an open accounting period to preview the depreciation, then save it as a draft. Nothing is posted from this screen.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('depreciation-runs.index') }}">Back</a>@endsection
@section('content')
@include('assets._policy-banner')
@foreach(['accounting_period_id','branch_id','status'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Convention: {{ $convention->label() }}</span><h2>Branch and period</h2></div></div>
    <form class="row g-3 align-items-end" method="GET" action="{{ route('depreciation-runs.create') }}">
        <div class="col-md-4"><label class="form-label" for="branch_id">Branch</label><select class="form-select" id="branch_id" name="branch_id" required><option value="">Select branch</option>@foreach($branches as $option)<option value="{{ $option->id }}" @selected($branch?->id===$option->id)>{{ $option->name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label" for="accounting_period_id">Open accounting period</label><select class="form-select" id="accounting_period_id" name="accounting_period_id" required><option value="">Select period</option>@foreach($periods as $option)<option value="{{ $option->id }}" @selected($period?->id===$option->id)>{{ $option->name }} ({{ $option->start_date->format('d M Y') }} – {{ $option->end_date->format('d M Y') }})</option>@endforeach</select></div>
        <div class="col-md-4"><button class="btn btn-outline-primary">Preview depreciation</button></div>
    </form>
    @if($periods->isEmpty())<div class="alert alert-warning mt-3 mb-0">No accounting period is open. Depreciation needs an open period in an open fiscal year.</div>@endif
</section>

@if($preview)
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Preview only — not saved, not posted</span><h2>{{ $branch->name }} — {{ $period->name }}</h2></div><span class="status-badge pending">Preview</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Asset</th><th>Category</th><th>In service</th><th class="text-end">Cost</th><th class="text-end">Accumulated before</th><th class="text-end">Depreciation</th><th class="text-end">Accumulated after</th><th class="text-end">Net book value after</th></tr></thead><tbody>
    @forelse($preview['lines'] as $line)
        <tr>
            <td><code>{{ $line['asset']->asset_code }}</code><span class="table-subtext">{{ $line['asset']->name }}</span></td>
            <td>{{ $line['asset']->category?->name }}</td>
            <td>{{ $line['asset']->in_service_date->format('d M Y') }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line['asset']->acquisition_cost,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line['accumulated_before'],2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line['depreciation_amount'],2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line['accumulated_after'],2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line['net_book_value_after'],2) }}</td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No asset is eligible for this period" description="Assets must be active and in service, not fully depreciated, and not already included in another run for this period." /></td></tr>
    @endforelse
    </tbody>@if($preview['lines'])<tfoot><tr><th colspan="5" class="text-end">Total depreciation ({{ $appSettings['currency_code'] }})</th><th class="text-end font-monospace">{{ number_format((float)$preview['total'],2) }}</th><th colspan="2"></th></tr></tfoot>@endif</table></div>
    @if($preview['lines'])
    <form method="POST" action="{{ route('depreciation-runs.store') }}" data-submit-once>@csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}"><input type="hidden" name="accounting_period_id" value="{{ $period->id }}">
        <p class="card-copy">The server recalculates every amount when the draft is saved and again before posting.</p>
        <div class="form-actions"><button class="btn btn-primary" data-confirm="Save this depreciation run as a draft? Nothing is posted until it is approved and posted.">Save as draft run</button><a class="btn btn-outline-primary" href="{{ route('depreciation-runs.index') }}">Cancel</a></div>
    </form>
    @endif
</section>
@endif
@endsection
