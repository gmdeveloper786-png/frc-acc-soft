@extends('layouts.app') @section('title','COA Approval Review') @section('eyebrow','Chart of Accounts') @section('page-title','COA Approval Review')
@section('page-description',$accountImport->original_filename.' — reviewing and approving rows creates no account and no financial posting.')
@section('page-actions')<div class="d-flex gap-2"><a class="btn btn-outline-primary" href="{{ route('account-imports.export',$accountImport) }}">Export approval summary (CSV)</a><a class="btn btn-outline-primary" href="{{ route('account-imports.create') }}">Back</a></div>@endsection
@section('content')
@php($s=$accountImport->summary??[])
@php($confirmed=$accountImport->status==='confirmed')
<div class="kpi-grid">
    <x-kpi-card label="Shared accounts" value="{{ $s['classification']['shared']['rows']??0 }}" icon="S" tone="navy" status="Source: Both" description="{{ $s['classification']['shared']['postable']??0 }} postable ledgers"/>
    <x-kpi-card label="Rehab-specific" value="{{ $s['classification']['rehab']['rows']??0 }}" icon="R" tone="teal" status="Source: Rehab Centre" description="{{ $s['classification']['rehab']['postable']??0 }} postable ledgers"/>
    <x-kpi-card label="School-only" value="{{ $s['classification']['school_only']['rows']??0 }}" icon="SC" tone="amber" status="Needs decision" description="Former separate School entity ledgers"/>
    <x-kpi-card label="Manual review" value="{{ $s['manual_review_flagged']??0 }}" icon="!" tone="rose" status="Needs decision" description="[Confirm], inter-unit, owner/private"/>
</div>
<div class="kpi-grid">
    <x-kpi-card label="Postable ledgers" value="{{ $s['postable_ledgers']??0 }}" icon="L" tone="navy" status="Workbook" description="{{ ($s['total_rows']??0)-($s['postable_ledgers']??0) }} parent hierarchy rows"/>
    <x-kpi-card label="Approved" value="{{ $s['review']['approved']??0 }}" icon="✓" tone="green" status="Reviewer decision" description="Rows cleared for import"/>
    <x-kpi-card label="Excluded" value="{{ $s['review']['excluded']??0 }}" icon="×" tone="rose" status="Reviewer decision" description="Rows that will not be imported"/>
    <x-kpi-card label="Pending" value="{{ $s['review']['pending']??0 }}" icon="?" tone="amber" status="Awaiting decision" description="{{ $s['already_existing']??0 }} existing · {{ $s['invalid_rows']??0 }} invalid"/>
</div>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Filter</span><h2>Find rows to review</h2></div><span class="status-badge {{ $confirmed?'success':'pending' }}">{{ $confirmed?'Imported':'Under review' }}</span></div>
    <form class="filter-bar filter-bar-wide" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Code or account name">
        <select class="form-select" name="classification"><option value="">All classifications</option>@foreach(['shared'=>'Shared','rehab'=>'Rehab-specific','school_only'=>'School-only','unclassified'=>'Unclassified'] as $value=>$label)<option value="{{ $value }}" @selected(request('classification')===$value)>{{ $label }}</option>@endforeach</select>
        <select class="form-select" name="flag"><option value="">All rows</option>@foreach(['manual'=>'Manual review','postable'=>'Postable ledgers','parents'=>'Parent accounts','existing'=>'Existing records','new'=>'Proposed new records'] as $value=>$label)<option value="{{ $value }}" @selected(request('flag')===$value)>{{ $label }}</option>@endforeach</select>
        <select class="form-select" name="proposed"><option value="">Any proposal</option>@foreach(['include','review','exclude'] as $value)<option value="{{ $value }}" @selected(request('proposed')===$value)>Proposed: {{ ucfirst($value) }}</option>@endforeach</select>
        <select class="form-select" name="review"><option value="">Any approval status</option>@foreach(['pending','approved','excluded'] as $value)<option value="{{ $value }}" @selected(request('review')===$value)>{{ ucfirst($value) }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('account-imports.show',$accountImport) }}">Reset</a>
    </form>
</section>

<form method="POST" action="{{ route('account-imports.decide',$accountImport) }}" class="surface-card mb-4" data-submit-once>@csrf
    <div class="card-heading"><div><span class="section-kicker">Explicit decision required</span><h2>Source accounts</h2></div><span class="status-badge neutral">{{ $rows->total() }} rows</span></div>
    <div class="alert alert-warning mt-3">The proposed decision is only a suggestion. Nothing is approved until an authorized reviewer records a decision, and nothing is imported until the approved rows are imported below. A school-labelled account is never excluded by its name alone: School Readiness Program is a normal FRC service.</div>
    @error('rows')<div class="alert alert-danger">{{ $message }}</div>@enderror
    @error('notes')<div class="alert alert-danger">{{ $message }}</div>@enderror
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th><input class="form-check-input" type="checkbox" data-check-all="[data-review-row]" aria-label="Select all rows on this page" @disabled($confirmed)></th><th>Code / Name</th><th>Hierarchy</th><th>Normal balance</th><th>Posting</th><th>Classification</th><th>Proposed decision</th><th>Reason / notes</th><th>Approval status</th></tr></thead><tbody>
    @forelse($rows as $row)
        <tr>
            <td><input class="form-check-input" type="checkbox" name="rows[]" value="{{ $row->id }}" data-review-row @disabled($confirmed)></td>
            <td><code>{{ $row->code }}</code><span class="table-subtext">{{ $row->name }}</span>@if($row->is_existing)<span class="status-badge neutral">Existing record</span>@else<span class="status-badge neutral">Proposed new</span>@endif</td>
            <td><span class="small">{{ $paths[$row->id] ?? '—' }}</span><span class="table-subtext">{{ str($row->level)->replace('_',' ')->title() }}{{ $row->parent_code ? ' · parent '.$row->parent_code : '' }}</span></td>
            <td>{{ ucfirst($row->normal_balance ?? '—') }}<span class="table-subtext">{{ ucfirst($row->account_type ?? '—') }}</span></td>
            <td>{{ $row->is_postable ? 'Postable ledger' : 'Parent only' }}</td>
            <td>{{ str($row->classification ?? 'unclassified')->replace('_',' ')->title() }}@if($row->requires_manual_review)<span class="d-block text-warning-emphasis small">Manual review</span>@endif</td>
            <td><span class="status-badge {{ ['include'=>'success','exclude'=>'danger'][$row->proposed_decision] ?? 'pending' }}">{{ ucfirst($row->proposed_decision ?? 'review') }}</span></td>
            <td class="small">{{ $row->proposed_reason }}@foreach($row->validation_errors??[] as $error)<span class="d-block text-danger">{{ $error }}</span>@endforeach @if($row->review_notes)<span class="d-block"><strong>Reviewer:</strong> {{ $row->review_notes }}</span>@endif</td>
            <td><span class="status-badge {{ ['approved'=>'success','excluded'=>'danger'][$row->review_status] ?? 'pending' }}">{{ ucfirst($row->review_status) }}</span>@if($row->reviewed_at)<span class="table-subtext">{{ $row->reviewed_at->format('d M Y') }}</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="9"><x-empty-state title="No rows match" description="Change or reset the filters to see workbook rows." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $rows->links() }}
    @unless($confirmed)
    <div class="row g-3 align-items-end mt-2">
        <div class="col-md-3"><label class="form-label" for="decision">Decision for selected rows</label><select class="form-select" id="decision" name="decision" required><option value="approved">Approve for import</option><option value="excluded">Exclude</option><option value="pending">Reset to pending</option></select></div>
        <div class="col-md-6"><label class="form-label" for="notes">Reason / notes</label><input class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" value="{{ old('notes') }}" maxlength="1000" placeholder="Required when approving school-only or flagged rows"></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" data-confirm="Record this review decision for the selected rows? No account is imported by this step.">Record decision</button></div>
    </div>
    @endunless
</form>

@unless($confirmed)
<form method="POST" action="{{ route('account-imports.confirm',$accountImport) }}" class="surface-card" data-submit-once>@csrf
    <div class="card-heading"><div><span class="section-kicker">Final step</span><h2>Import approved accounts</h2></div><span class="status-badge {{ $approvedCount?'success':'neutral' }}">{{ $approvedCount }} new approved</span></div>
    <p class="card-copy">Only rows with an approved decision are created in the Chart of Accounts, parents before children. Excluded and pending rows are left out. This step can be run once per preview and creates no balances or journals.</p>
    <div class="form-actions"><button class="btn btn-primary" @disabled($approvedCount===0) data-confirm="Import {{ $approvedCount }} approved accounts into the Chart of Accounts? Make sure the client/accountant has approved this exact selection.">Import approved accounts</button></div>
</form>
@endunless
@endsection
