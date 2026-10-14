@extends('layouts.app') @section('title','Fixed Asset '.$asset->asset_code) @section('eyebrow','Fixed Assets') @section('page-title',$asset->asset_code.' — '.$asset->name)
@section('page-description',$asset->category->name.' • '.$asset->branch->name.' • '.$asset->status->label())
@section('page-actions')
<div class="d-flex gap-2">
    @can('fixed_assets.update') @if($asset->status!==\App\Enums\FixedAssetStatus::Retired)<a class="btn btn-outline-primary" href="{{ route('fixed-assets.edit',$asset) }}">{{ $asset->status->isEditable() ? 'Edit draft' : 'Edit details' }}</a>@endif @endcan
    <a class="btn btn-outline-primary" href="{{ route('fixed-assets.index') }}">Back</a>
</div>
@endsection
@section('content')
@php
    $status = $asset->status;
    $currency = $appSettings['currency_code'];
    $depreciable = $asset->depreciableAmount();
@endphp
@include('assets._policy-banner')
@foreach(['status','acquisition_cost','residual_value','useful_life_months','opening_accumulated_depreciation','in_service_date','asset_account_id','accumulated_depreciation_account_id','depreciation_expense_account_id','reason','document'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach

<div class="kpi-grid">
    <x-kpi-card label="Acquisition cost" value="{{ $currency }} {{ number_format((float)$asset->acquisition_cost,2) }}" icon="C" tone="navy" status="{{ $status->label() }}" description="Recognised in the ledger by the purchase bill or opening balances"/>
    <x-kpi-card label="Accumulated depreciation" value="{{ $currency }} {{ number_format((float)$accumulated,2) }}" icon="D" tone="amber" status="Opening + posted runs" description="Opening {{ number_format((float)$asset->opening_accumulated_depreciation,2) }} plus posted depreciation"/>
    <x-kpi-card label="Net book value" value="{{ $currency }} {{ number_format((float)$netBookValue,2) }}" icon="N" tone="teal" status="Cost less accumulated" description="Never below the residual value of {{ number_format((float)$asset->residual_value,2) }}"/>
    <x-kpi-card label="Depreciable amount" value="{{ $currency }} {{ number_format((float)$depreciable,2) }}" icon="S" tone="rose" status="Straight-line" description="Over {{ $asset->useful_life_months }} months"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Register entry — no General Ledger posting</span><h2>Asset details</h2></div><span class="status-badge {{ $status->badge() }}">{{ $status->label() }}</span></div>
    <div class="row g-3">
        <div class="col-md-3"><small>Category</small><strong class="d-block">{{ $asset->category->code }} — {{ $asset->category->name }}</strong></div>
        <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $asset->branch->name }}</strong></div>
        <div class="col-md-3"><small>Location</small><strong class="d-block">{{ $asset->location ?: '—' }}</strong></div>
        <div class="col-md-3"><small>Custodian</small><strong class="d-block">{{ $asset->custodian ?: '—' }}</strong></div>
        <div class="col-md-3"><small>Acquisition date</small><strong class="d-block">{{ $asset->acquisition_date->format('d M Y') }}</strong></div>
        <div class="col-md-3"><small>In-service date</small><strong class="d-block">{{ $asset->in_service_date->format('d M Y') }}</strong></div>
        <div class="col-md-3"><small>Useful life</small><strong class="d-block">{{ $asset->useful_life_months }} months</strong></div>
        <div class="col-md-3"><small>Residual value</small><strong class="d-block font-monospace">{{ number_format((float)$asset->residual_value,2) }}</strong></div>
        <div class="col-md-3"><small>Vendor</small><strong class="d-block">{{ $asset->vendor?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Purchase bill</small><strong class="d-block">@if($asset->purchaseBill) @can('purchase_bills.view')<a href="{{ route('purchase-bills.show',$asset->purchaseBill) }}">{{ $asset->purchaseBill->bill_number }}</a>@else {{ $asset->purchaseBill->bill_number }} @endcan @else — @endif</strong></div>
        <div class="col-md-3"><small>Registered by</small><strong class="d-block">{{ $asset->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-3"><small>Activated</small><strong class="d-block">{{ $asset->activated_at?->format('d M Y H:i') ?? '—' }}</strong></div>
        <div class="col-md-4"><small>Asset ledger</small><strong class="d-block">{{ $asset->assetAccount?->code }} — {{ $asset->assetAccount?->name }}</strong></div>
        <div class="col-md-4"><small>Accumulated depreciation ledger</small><strong class="d-block">{{ $asset->accumulatedDepreciationAccount?->code }} — {{ $asset->accumulatedDepreciationAccount?->name }}</strong></div>
        <div class="col-md-4"><small>Depreciation expense ledger</small><strong class="d-block">{{ $asset->depreciationExpenseAccount?->code }} — {{ $asset->depreciationExpenseAccount?->name }}</strong></div>
        <div class="col-12"><small>Description</small><p class="mb-0">{{ $asset->description ?: '—' }}</p></div>
        @if($asset->retirement_reason)<div class="col-12"><div class="alert alert-warning mb-0"><strong>Retired {{ $asset->retired_at?->format('d M Y') }}:</strong> {{ $asset->retirement_reason }}. No disposal gain or loss entry was posted by this module.</div></div>@endif
    </div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Workflow</span><h2>Actions</h2></div></div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        @if($status===\App\Enums\FixedAssetStatus::Draft)
            @can('fixed_assets.update')
                <form method="POST" action="{{ route('fixed-assets.activate',$asset) }}" data-submit-once>@csrf<button class="btn btn-primary" data-confirm="Activate this asset? Cost, residual value, useful life, dates, branch and accounts can no longer be changed. No accounting entry is posted.">Activate asset</button></form>
            @else
                <span class="status-badge pending">Awaiting activation by an authorized user</span>
            @endcan
        @endif
        @if(in_array($status,[\App\Enums\FixedAssetStatus::Active,\App\Enums\FixedAssetStatus::FullyDepreciated],true)) @can('fixed_assets.retire')<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#retireModal">Retire asset</button>@endcan @endif
        @if($status===\App\Enums\FixedAssetStatus::Retired)<span class="status-badge danger">Retired — no further depreciation</span>@endif
    </div>
    <p class="card-copy mt-3 mb-0">Activation and retirement change the register only. Disposal proceeds and gain or loss on disposal are not handled by this module and must be recorded by an approved journal.</p>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">From posted depreciation runs — never recalculated</span><h2>Posted depreciation history</h2></div><span class="status-badge neutral">{{ count($schedule['history']) }} periods</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Period</th><th class="text-end">Depreciation</th><th class="text-end">Accumulated after</th><th class="text-end">Net book value after</th><th></th></tr></thead><tbody>
    @forelse($schedule['history'] as $row)
        <tr><td>{{ $row['period_name'] }}<span class="table-subtext">{{ $row['period_start']->format('d M Y') }} – {{ $row['period_end']->format('d M Y') }}</span></td><td class="text-end font-monospace">{{ number_format((float)$row['amount'],2) }}</td><td class="text-end font-monospace">{{ number_format((float)$row['accumulated_after'],2) }}</td><td class="text-end font-monospace">{{ number_format((float)$row['net_book_value_after'],2) }}</td><td>@can('depreciation.view')<a class="btn btn-sm btn-outline-primary" href="{{ route('depreciation-runs.show',$row['run_id']) }}">Run</a>@endcan</td></tr>
    @empty
        <tr><td colspan="5"><x-empty-state title="No depreciation posted yet" description="Depreciation appears here once a depreciation run that includes this asset has been posted." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Projection only — not posted</span><h2>Depreciation schedule (projection)</h2></div><span class="status-badge pending">Projection</span></div>
    <p class="card-copy">Projected with the current convention “{{ $schedule['convention'] }}”, assuming a run is posted every month. Actual amounts are fixed only when a run is posted.@if(! $policyApproved) The depreciation policy is not approved, so this is a preview and none of it can be posted yet.@endif</p>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>#</th><th>Month</th><th class="text-end">Projected depreciation</th><th class="text-end">Accumulated after</th><th class="text-end">Net book value after</th></tr></thead><tbody>
    @forelse($schedule['projection'] as $index => $row)
        <tr><td>{{ $index + 1 }}</td><td>{{ $row['period_start']->format('M Y') }}</td><td class="text-end font-monospace">{{ number_format((float)$row['amount'],2) }}</td><td class="text-end font-monospace">{{ number_format((float)$row['accumulated_after'],2) }}</td><td class="text-end font-monospace">{{ number_format((float)$row['net_book_value_after'],2) }}</td></tr>
    @empty
        <tr><td colspan="5"><x-empty-state title="Nothing left to project" description="{{ $status===\App\Enums\FixedAssetStatus::Retired ? 'A retired asset is not depreciated.' : 'This asset is fully depreciated.' }}" /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Private storage — PDF, JPG, PNG or WEBP up to 5 MB</span><h2>Supporting documents</h2></div><span class="status-badge neutral">{{ $asset->documents->count() }} files</span></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>File</th><th>Type</th><th class="text-end">Size</th><th>Uploaded by</th><th>Uploaded</th><th></th></tr></thead><tbody>
    @forelse($asset->documents as $document)
        <tr><td>{{ $document->original_name }}</td><td>{{ $document->mime_type }}</td><td class="text-end">{{ number_format($document->size_bytes / 1024, 1) }} KB</td><td>{{ $document->uploader?->name ?? '—' }}</td><td>{{ $document->created_at?->format('d M Y H:i') }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('fixed-assets.documents.download',[$asset,$document]) }}">Download</a></td></tr>
    @empty
        <tr><td colspan="6"><x-empty-state title="No documents uploaded" description="Attach the invoice, warranty or handover note for this asset." /></td></tr>
    @endforelse
    </tbody></table></div>
    @can('fixed_assets.update') @if($status!==\App\Enums\FixedAssetStatus::Retired)
    <form class="row g-3 align-items-end mt-1" method="POST" action="{{ route('fixed-assets.documents.store',$asset) }}" enctype="multipart/form-data" data-submit-once>@csrf
        <div class="col-md-6"><label class="form-label" for="document">Add document</label><input class="form-control @error('document') is-invalid @enderror" id="document" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required></div>
        <div class="col-md-3"><button class="btn btn-primary">Upload</button></div>
    </form>
    @endif @endcan
</section>

<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Append-only</span><h2>Event history</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>When</th><th>Event</th><th>By</th><th>Details</th></tr></thead><tbody>
    @forelse($asset->events as $event)
        <tr><td>{{ $event->created_at?->format('d M Y H:i') }}</td><td><span class="status-badge neutral">{{ ucfirst(str_replace('_',' ',$event->event)) }}</span></td><td>{{ $event->actor?->name ?? '—' }}</td><td>{{ $event->details ?: '—' }}</td></tr>
    @empty
        <tr><td colspan="4"><x-empty-state title="No history recorded" description="Events are recorded as the asset is registered, activated, depreciated and retired." /></td></tr>
    @endforelse
    </tbody></table></div>
</section>

@can('fixed_assets.retire')
<div class="modal fade" id="retireModal" tabindex="-1" aria-labelledby="retireModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('fixed-assets.retire',$asset) }}" data-submit-once>@csrf
    <div class="modal-header"><h2 class="modal-title fs-5" id="retireModalTitle">Retire {{ $asset->asset_code }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p>Retirement is permanent and stops depreciation. Net book value at retirement: {{ $currency }} {{ number_format((float)$netBookValue,2) }}. No disposal entry is posted; record any disposal gain or loss by journal.</p><label class="form-label" for="retireReason">Reason</label><textarea class="form-control" id="retireReason" name="reason" rows="3" minlength="5" maxlength="2000" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Keep asset</button><button class="btn btn-outline-danger">Retire asset</button></div>
</form></div></div>
@endcan
@endsection
