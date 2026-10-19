@extends('layouts.app')

@section('title', 'Prepare Opening Balance')
@section('eyebrow', 'Opening Balances')
@section('page-title', 'Prepare Opening Balance Draft')
@section('page-description', 'Enter verified Balance Sheet amounts. Saving creates a draft only and does not post a journal.')

@section('content')
@php
    $typeLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'];
    $fallbackGroups = ['asset' => 'Other Assets', 'liability' => 'Other Liabilities', 'equity' => 'Equity and Fund Balances'];
    $lineIndexByAccount = $accounts->pluck('id')->flip();
    $accountsByType = $accounts->groupBy(fn ($account) => $account->account_type->value);
@endphp

<form method="POST" action="{{ route('opening-balances.store') }}" class="surface-card opening-balance-form" data-opening-balance-form>
    @csrf

    <div class="form-grid">
        <div>
            <label class="form-label" for="opening-fiscal-year">Fiscal year</label>
            <select id="opening-fiscal-year" class="form-select" name="fiscal_year_id" data-opening-fiscal-year required>
                @foreach($fiscalYears as $year)
                    <option value="{{ $year->id }}" data-start-date="{{ $year->start_date->toDateString() }}" @selected((string) old('fiscal_year_id') === (string) $year->id)>{{ $year->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="opening-branch">Branch</label>
            <select id="opening-branch" class="form-select" name="branch_id" required>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="opening-date">Opening date</label>
            <input id="opening-date" type="date" readonly class="form-control" name="opening_date" data-opening-date value="{{ old('opening_date', $openingDate) }}">
        </div>
        <div>
            <label class="form-label" for="opening-source-reference">Source reference</label>
            <input id="opening-source-reference" class="form-control" name="source_reference" value="{{ old('source_reference') }}" placeholder="e.g. Audited trial balance reference">
        </div>
    </div>

    <div class="opening-balance-toolbar mt-4" aria-label="Ledger account tools">
        <div class="opening-search-wrap">
            <label class="form-label" for="opening-account-search">Search ledger accounts</label>
            <div class="input-group">
                <input id="opening-account-search" type="search" class="form-control" data-opening-search placeholder="Search by GL code or account name" autocomplete="off">
                <button class="btn btn-outline-primary" type="button" data-opening-search-clear disabled>Clear search</button>
            </div>
        </div>

        <fieldset class="opening-type-filter">
            <legend class="form-label">Account type</legend>
            <div class="opening-filter-buttons" role="group" aria-label="Filter ledger accounts by type">
                <button class="btn btn-sm active" type="button" data-opening-type-filter="all" aria-pressed="true">All Accounts</button>
                <button class="btn btn-sm" type="button" data-opening-type-filter="asset" aria-pressed="false">Assets</button>
                <button class="btn btn-sm" type="button" data-opening-type-filter="liability" aria-pressed="false">Liabilities</button>
                <button class="btn btn-sm" type="button" data-opening-type-filter="equity" aria-pressed="false">Equity</button>
            </div>
        </fieldset>

        <div class="opening-toolbar-actions">
            <div class="form-check">
                <input id="opening-entered-only" class="form-check-input" type="checkbox" data-opening-entered-only>
                <label class="form-check-label" for="opening-entered-only">Show only entered balances</label>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Account section controls">
                <button class="btn btn-outline-primary" type="button" data-opening-expand-all>Expand all</button>
                <button class="btn btn-outline-primary" type="button" data-opening-collapse-all>Collapse all</button>
            </div>
        </div>
    </div>

    <div class="opening-totals-panel" data-opening-totals aria-live="polite">
        <div><span>Total Debit</span><strong data-opening-total-debit>PKR 0.00</strong></div>
        <div><span>Total Credit</span><strong data-opening-total-credit>PKR 0.00</strong></div>
        <div><span>Difference</span><strong data-opening-difference>PKR 0.00</strong></div>
        <span class="status-badge neutral" data-opening-balance-status>No amounts entered</span>
    </div>

    <div class="card-heading opening-ledger-heading">
        <div>
            <h2>Eligible Balance Sheet ledgers</h2>
            <p>Search and filters only change what is visible. Every input remains in this form until submission.</p>
        </div>
        <span class="status-badge neutral">Draft only</span>
    </div>

    <div class="opening-account-sections" data-opening-account-sections>
        @foreach($typeLabels as $type => $typeLabel)
            @php
                $typeAccounts = $accountsByType->get($type, collect());
                $groupedAccounts = $typeAccounts->groupBy(fn ($account) => $account->parent?->name ?: $fallbackGroups[$type]);
            @endphp
            @if($typeAccounts->isNotEmpty())
                <section class="opening-account-type" data-opening-type-section="{{ $type }}">
                    <h3>{{ $typeLabel }}</h3>

                    @foreach($groupedAccounts as $groupName => $groupAccounts)
                        @php($groupId = 'opening-group-'.$type.'-'.$loop->index)
                        <section class="opening-account-group" data-opening-group>
                            <button class="opening-group-toggle" type="button" data-opening-group-toggle aria-expanded="true" aria-controls="{{ $groupId }}">
                                <span>{{ $groupName }}</span>
                                <span><span data-opening-group-count>{{ $groupAccounts->count() }}</span> ledgers <span class="opening-group-chevron" aria-hidden="true">^</span></span>
                            </button>
                            <div id="{{ $groupId }}" data-opening-group-body>
                                <div class="opening-ledger-grid opening-ledger-labels" aria-hidden="true">
                                    <span>Account</span><span>Normal</span><span>Debit</span><span>Credit</span><span>Source reference</span>
                                </div>

                                @foreach($groupAccounts as $account)
                                    @php($i = $lineIndexByAccount[$account->id])
                                    <div class="opening-ledger-grid opening-account-row"
                                         data-opening-account-row
                                         data-account-type="{{ $type }}"
                                         data-account-search="{{ Str::lower($account->code.' '.$account->name) }}">
                                        <div class="opening-account-identity">
                                            <code>{{ $account->code }}</code>
                                            <strong>{{ $account->name }}</strong>
                                            <input type="hidden" name="lines[{{ $i }}][account_id]" value="{{ $account->id }}">
                                        </div>
                                        <div class="opening-normal-balance"><span class="opening-mobile-label">Normal</span>{{ ucfirst($account->normal_balance->value) }}</div>
                                        <div>
                                            <label class="opening-mobile-label" for="opening-debit-{{ $account->id }}">Debit</label>
                                            <input id="opening-debit-{{ $account->id }}" class="form-control amount-input @error("lines.$i.debit") is-invalid @enderror" name="lines[{{ $i }}][debit]" inputmode="decimal" data-opening-debit value="{{ old("lines.$i.debit") }}" placeholder="0.00" aria-label="Debit for {{ $account->code }} {{ $account->name }}">
                                        </div>
                                        <div>
                                            <label class="opening-mobile-label" for="opening-credit-{{ $account->id }}">Credit</label>
                                            <input id="opening-credit-{{ $account->id }}" class="form-control amount-input @error("lines.$i.credit") is-invalid @enderror" name="lines[{{ $i }}][credit]" inputmode="decimal" data-opening-credit value="{{ old("lines.$i.credit") }}" placeholder="0.00" aria-label="Credit for {{ $account->code }} {{ $account->name }}">
                                        </div>
                                        <div class="opening-reference-field">
                                            <label class="opening-mobile-label" for="opening-reference-{{ $account->id }}">Source reference</label>
                                            <input id="opening-reference-{{ $account->id }}" class="form-control @error("lines.$i.source_reference") is-invalid @enderror" name="lines[{{ $i }}][source_reference]" data-opening-reference value="{{ old("lines.$i.source_reference") }}" aria-label="Source reference for {{ $account->code }} {{ $account->name }}">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </section>
            @endif
        @endforeach
        @if($accounts->isEmpty())
            <x-empty-state title="No eligible ledgers" description="Create or import approved Balance Sheet ledger accounts first." />
        @endif
    </div>

    <div class="opening-filter-empty" data-opening-filter-empty hidden>
        <strong>No matching ledger accounts</strong>
        <span>Change the search text or account filters to see eligible ledgers.</span>
    </div>

    <div class="form-actions">
        <a class="btn btn-outline-primary" href="{{ route('opening-balances.index') }}">Cancel</a>
        <button class="btn btn-primary" @disabled($accounts->isEmpty())>Validate & save draft</button>
    </div>
</form>
@endsection
