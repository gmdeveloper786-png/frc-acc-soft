@extends('layouts.app') @section('title','GL Mapping') @section('eyebrow','Accounting Setup') @section('page-title','GL Mapping')
@section('page-description','Connect operational workflows to approved postable ledgers. A missing required mapping blocks posting with a configuration error.')
@section('page-actions')@can('accounting_setup.readiness')<a class="btn btn-outline-primary" href="{{ route('accounting-setup.readiness') }}">Setup readiness</a>@endcan @endsection
@section('content')
@error('account_id')<div class="alert alert-danger">{{ $message }}</div>@enderror

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Organisation level</span><h2>Control account mappings</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Mapping</th><th>Required type</th><th>Mapped ledger</th><th>Status</th>@can('account_mappings.manage')<th>Change</th>@endcan</tr></thead><tbody>
    @foreach($controls as $control)
        <tr>
            <td><strong>{{ $control['key']->label() }}</strong><span class="table-subtext">{{ $control['key']->description() }}</span></td>
            <td>{{ ucfirst($control['key']->accountType()->value) }}<span class="table-subtext">{{ ucfirst($control['key']->accountType()->defaultNormalBalance()->value) }} balance</span></td>
            <td>@if($control['mapping'])<code>{{ $control['mapping']->account->code }}</code><span class="table-subtext">{{ $control['mapping']->account->name }}</span>@else — @endif</td>
            <td>
                @if($control['valid'])<span class="status-badge success">Valid</span>
                @elseif($control['mapping'])<span class="status-badge danger">Invalid ledger</span>
                @elseif($control['key']->isRequired())<span class="status-badge danger">Required — not mapped</span>
                @else<span class="status-badge neutral">Optional — not mapped</span>@endif
            </td>
            @can('account_mappings.manage')
            <td><form class="d-flex gap-2" method="POST" action="{{ route('account-mappings.update',$control['key']->value) }}">@csrf @method('PUT')
                <select class="form-select" name="account_id" aria-label="{{ $control['key']->label() }} ledger"><option value="">Not mapped</option>@foreach($control['options'] as $account)<option value="{{ $account->id }}" @selected($control['mapping']?->account_id===$account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach</select>
                <button class="btn btn-primary" data-confirm="Save the {{ $control['key']->label() }} mapping? Future postings will use this ledger.">Save</button>
            </form>@if($control['options']->isEmpty())<span class="table-subtext">No active postable {{ $control['key']->accountType()->value }} ledger exists yet.</span>@endif</td>
            @endcan
        </tr>
    @endforeach
    </tbody></table></div>
</section>

<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Service level</span><h2>Service-specific fee income</h2></div><span class="status-badge neutral">{{ $services->total() }} services</span></div>
    <p class="card-copy">Services are mastered in FRC and are read-only here. A service without its own ledger uses General Fee Income. School Readiness Program is mapped like every other service.</p>
    <form class="filter-bar" method="GET"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Search service"><select class="form-select" name="mapped"><option value="">All services</option><option value="yes" @selected(request('mapped')==='yes')>With own ledger</option><option value="no" @selected(request('mapped')==='no')>Using general fee income</option></select><button class="btn btn-primary">Filter</button></form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Service</th><th>Fee income ledger</th><th>Status</th>@can('account_mappings.manage')<th>Change</th>@endcan</tr></thead><tbody>
    @forelse($services as $service)
        <tr>
            <td><strong>{{ $service->name }}</strong><span class="table-subtext">FRC service #{{ $service->frc_service_id }} · {{ $service->status }}</span></td>
            <td>@if($service->revenueAccount)<code>{{ $service->revenueAccount->code }}</code><span class="table-subtext">{{ $service->revenueAccount->name }}</span>@else Uses General Fee Income @endif</td>
            <td>@if(!$service->revenue_account_id)<span class="status-badge neutral">Default</span>@elseif($mappings->isUsable($service->revenueAccount,\App\Enums\AccountType::Income))<span class="status-badge success">Valid</span>@else<span class="status-badge danger">Invalid ledger</span>@endif</td>
            @can('account_mappings.manage')
            <td><form class="d-flex gap-2" method="POST" action="{{ route('account-mappings.services.update',$service) }}">@csrf @method('PUT')
                <select class="form-select" name="account_id" aria-label="Fee income ledger for {{ $service->name }}"><option value="">Use General Fee Income</option>@foreach($incomeAccounts as $account)<option value="{{ $account->id }}" @selected($service->revenue_account_id===$account->id)>{{ $account->code }} — {{ $account->name }}</option>@endforeach</select>
                <button class="btn btn-primary">Save</button>
            </form></td>
            @endcan
        </tr>
    @empty
        <tr><td colspan="4"><x-empty-state title="No services found" description="Synchronize FRC services or change the filter." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $services->links() }}
</section>

<div class="content-grid two-columns">
    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">Managed under Cash &amp; Bank</span><h2>Cash and bank accounts</h2></div>@can('financial_accounts.view')<a class="btn btn-sm btn-outline-primary" href="{{ route('financial-accounts.index') }}">Open</a>@endcan</div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Account</th><th>Ledger</th><th>Status</th></tr></thead><tbody>
        @forelse($financialAccounts as $account)
            <tr><td><strong>{{ $account->name }}</strong><span class="table-subtext">{{ ucfirst($account->type) }} · {{ $account->branch?->name }}</span></td><td><code>{{ $account->account?->code }}</code><span class="table-subtext">{{ $account->account?->name }}</span></td><td>@if(!$account->is_active)<span class="status-badge neutral">Inactive</span>@elseif($mappings->isUsable($account->account,\App\Enums\AccountType::Asset))<span class="status-badge success">Valid</span>@else<span class="status-badge danger">Invalid ledger</span>@endif</td></tr>
        @empty
            <tr><td colspan="3"><x-empty-state title="No cash or bank account" description="Each cash and bank account is linked to its own asset ledger under Cash & Bank." /></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
    <section class="surface-card">
        <div class="card-heading"><div><span class="section-kicker">Managed under Donation Categories</span><h2>Donation income</h2></div>@can('donations.manage_categories')<a class="btn btn-sm btn-outline-primary" href="{{ route('donation-categories.index') }}">Open</a>@endcan</div>
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Category</th><th>Income ledger</th><th>Status</th></tr></thead><tbody>
        @forelse($donationCategories as $category)
            <tr><td><strong>{{ $category->name }}</strong></td><td>@if($category->incomeAccount)<code>{{ $category->incomeAccount->code }}</code><span class="table-subtext">{{ $category->incomeAccount->name }}</span>@else — @endif</td><td>@if(!$category->is_active)<span class="status-badge neutral">Inactive</span>@elseif(!$category->income_account_id)<span class="status-badge danger">Not mapped</span>@elseif($mappings->isUsable($category->incomeAccount,\App\Enums\AccountType::Income))<span class="status-badge success">Valid</span>@else<span class="status-badge danger">Invalid ledger</span>@endif</td></tr>
        @empty
            <tr><td colspan="3"><x-empty-state title="No donation category" description="Donation categories are seeded with the application." /></td></tr>
        @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
