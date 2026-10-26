@extends('layouts.app') @section('title','Bank Statements') @section('eyebrow','Bank reconciliation') @section('page-title','Bank Statements')
@section('page-description','Import bank statements (CSV or Excel) for the bank accounts of your branches. Files are stored privately and each file can be imported once per account.')
@section('page-actions')<a class="btn btn-outline-primary" href="{{ route('bank-reconciliations.index') }}">Reconciliations</a>@endsection
@section('content')
@php
    $amount = fn ($value) => \App\Services\BankReconciliation\Amount::format($value);
@endphp
@error('statement_file')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('statement')<div class="alert alert-danger">{{ $message }}</div>@enderror

{{-- $accounts are the bank accounts this user may upload statements for (shared accounts need access to every branch). --}}
@if(auth()->user()->can('bank_reconciliation.prepare') && $accounts->isNotEmpty())
<form method="POST" action="{{ route('bank-statements.store') }}" enctype="multipart/form-data" data-submit-once>@csrf
<section class="surface-card mb-4">
    <div class="card-heading"><div><span class="section-kicker">Step 1 — upload; nothing is imported until the columns are mapped</span><h2>Upload a statement</h2></div></div>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label" for="financial_account_id">Bank account</label>
            <select class="form-select" id="financial_account_id" name="financial_account_id" required>
                <option value="">Select a bank account</option>
                @foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int) old('financial_account_id', request('financial_account_id')) === $account->id)>{{ $account->name }} — {{ $account->branch->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-5"><label class="form-label" for="statement_file">Statement file (.csv or .xlsx, up to 5 MB)</label><input class="form-control" type="file" id="statement_file" name="statement_file" accept=".csv,.xlsx" required></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Upload</button></div>
    </div>
</section>
</form>
@elseif(auth()->user()->can('bank_reconciliation.prepare'))
<div class="alert alert-info mb-4">No bank accounts available for statement upload.</div>
@endif

<section class="surface-card">
    <form class="filter-bar" method="GET">
        <select class="form-select" name="financial_account_id" aria-label="Bank account"><option value="">All bank accounts</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int) request('financial_account_id') === $account->id)>{{ $account->name }} — {{ $account->branch->name }}</option>@endforeach</select>
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-primary" href="{{ route('bank-statements.index') }}">Reset</a>
    </form>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Bank account</th><th>Period</th><th class="text-end">Opening</th><th class="text-end">Closing</th><th>Lines</th><th>Status</th><th>Uploaded by</th><th>Actions</th></tr></thead><tbody>
    @forelse($statements as $statement)
        <tr>
            <td>{{ $statement->financialAccount->name }}<span class="table-subtext">{{ $statement->branch->name }} • {{ $statement->original_filename }}</span></td>
            <td>@if($statement->period_from){{ $statement->period_from->format('d M Y') }} – {{ $statement->period_to->format('d M Y') }}@else — @endif</td>
            <td class="text-end font-monospace">{{ $statement->opening_balance !== null ? $amount($statement->opening_balance) : '—' }}</td>
            <td class="text-end font-monospace">{{ $statement->closing_balance !== null ? $amount($statement->closing_balance) : '—' }}</td>
            <td>{{ $statement->line_count }}</td>
            <td><span class="status-badge {{ $statement->badge() }}">{{ $statement->statusLabel() }}</span></td>
            <td>{{ $statement->uploader?->name ?? '—' }}<span class="table-subtext">{{ $statement->created_at->format('d M Y H:i') }}</span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('bank-statements.show', $statement) }}">{{ $statement->isImported() ? 'View' : 'Map columns' }}</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state title="No statements" description="Upload a bank statement to start." /></td></tr>
    @endforelse
    </tbody></table></div>
    {{ $statements->links() }}
</section>
@endsection
