@extends('layouts.app') @section('title','Funds') @section('eyebrow','Funds & Grants') @section('page-title','Funds')
@section('page-description','Funds separate General, Zakat/Charity, Donor-restricted, Grant project and Capital money. A fund is optional on journal lines, transactions and donation categories.')
@section('page-actions')@can('funds.manage')<a class="btn btn-primary" href="{{ route('funds.create') }}">+ New fund</a>@endcan @endsection
@section('content')
@can('funds.manage')
    @if($missingWorkbookCodes !== [])
        <section class="surface-card mb-4">
            <div class="card-heading"><div><span class="section-kicker">Client workbook</span><h2>Load the workbook fund codes</h2></div></div>
            <p class="table-subtext">The "Fund Codes" sheet of the Chart of Accounts workbook lists GEN General Fund, DNR Donor-Restricted Fund, ZKT Zakat / Charity Fund (marked [Confirm] in the workbook), GRT Grant Project Fund and CAP Capital / Building Fund. Not yet created: <strong>{{ implode(', ', $missingWorkbookCodes) }}</strong>. Loading adds only the missing codes and never changes an existing fund. Grant sub-codes (GRT-01, GRT-02 ...) are created one per real grant.</p>
            <form method="POST" action="{{ route('funds.workbook-codes') }}" data-submit-once>@csrf<button class="btn btn-outline-primary" data-confirm="Create the missing workbook fund codes?">Load workbook fund codes</button></form>
        </section>
    @endif
@endcan

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Restricted-fund rule</span><h2>Enforced — a restricted fund can be charged only to its permitted ledgers</h2></div><span class="status-badge danger">Always enforced</span></div>
    <p class="table-subtext">A debit to an expense ledger, or to a non-cash asset ledger, tagged with a restricted fund is refused unless that ledger is one of the fund's permitted ledgers. A restricted fund with no permitted ledgers cannot be charged until they are set on the fund. Manual journals, payments, expenses and petty cash are checked when saved and again at posting. Unrestricted funds are not limited.</p>
</section>

<section class="surface-card">
    <form class="filter-bar" method="GET">
        <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Code or name">
        <select class="form-select" name="category"><option value="">All categories</option>@foreach(\App\Models\Fund::CATEGORIES as $value => $label)<option value="{{ $value }}" @selected(request('category')===$value)>{{ $label }}</option>@endforeach</select>
        <select class="form-select" name="status"><option value="">All statuses</option><option value="active" @selected(request('status')==='active')>Active</option><option value="inactive" @selected(request('status')==='inactive')>Inactive</option></select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('funds.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Category</th><th>Parent</th><th>Donor / grantor</th><th class="text-end">Grant amount</th><th>Grant period</th><th class="text-end">Permitted ledgers</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($funds as $fund)
        <tr>
            <td><code>{{ $fund->code }}</code></td>
            <td>{{ $fund->name }}@if($fund->restriction_notes)<span class="table-subtext">{{ $fund->restriction_notes }}</span>@endif</td>
            <td><span class="status-badge {{ $fund->isRestricted() ? 'pending' : 'neutral' }}">{{ \App\Models\Fund::TYPES[$fund->type] ?? $fund->type }}</span></td>
            <td>{{ \App\Models\Fund::CATEGORIES[$fund->category] ?? $fund->category }}</td>
            <td>{{ $fund->parent?->code ?? '—' }}</td>
            <td>{{ $fund->donor_name ?? '—' }}</td>
            <td class="text-end font-monospace">{{ $fund->grant_amount !== null ? number_format((float) $fund->grant_amount, 2) : '—' }}</td>
            <td>{{ $fund->start_date || $fund->end_date ? ($fund->start_date?->format('d M Y') ?? 'Open').' – '.($fund->end_date?->format('d M Y') ?? 'open') : '—' }}</td>
            <td class="text-end">{{ $fund->permitted_accounts_count ?: 'Any' }}</td>
            <td><span class="status-badge {{ $fund->is_active ? 'success' : 'neutral' }}">{{ $fund->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="d-flex gap-1">
                @if(auth()->user()->canAny(['funds.manage', 'grants.manage']))<a class="btn btn-sm btn-outline-primary" href="{{ route('funds.edit',$fund) }}">Edit</a>@endif
                @if($fund->isGrant())@can('fund_reports.view')<a class="btn btn-sm btn-outline-primary" href="{{ route('fund-reports.donor-report',$fund) }}">Donor report</a>@endcan @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="11"><x-empty-state title="No funds found" description="No fund exists yet. Create one, or load the fund codes of the client workbook." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $funds->links() }}
</section>
@endsection
