@extends('layouts.app')

@section('title', 'Payables & Aging')
@section('page-title', 'Accounts Payable Outstanding')

@section('page-actions')
    @if ($bills->isNotEmpty() || $obligations->isNotEmpty())
        @can('finance_reports.export')
            @include('accounting.reports._export-links', ['exportRoute' => 'reports.payables.export'])
        @endcan
    @endif
@endsection

@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <form class="row g-3">
                <div class="col-md-3"><input class="form-control" type="date" name="as_of" value="{{ $asOf->toDateString() }}"></div>
                <div class="col-md-2"><button class="btn btn-primary">As of</button></div>
            </form>
            <div class="row g-3 mt-2">
                @foreach (['Current', '1-30', '31-60', '61-90', '90+'] as $bucket)
                    <div class="col"><div class="p-3 border rounded"><small>{{ $bucket }} days</small><strong class="d-block">PKR {{ \App\Services\AccountingDecimal::format((string) ($aging[$bucket] ?? 0), 2, 4) }}</strong></div></div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="card"><div class="card-body"><div class="table-responsive">
        <table class="table">
            <thead><tr><th>Vendor</th><th>Source</th><th>Due / Date</th><th>Branch</th><th class="text-end">Outstanding</th></tr></thead>
            <tbody>
                @foreach ($bills as $bill)
                    <tr><td>{{ $bill->vendor->name }}</td><td><a href="{{ route('purchase-bills.show', $bill) }}">{{ $bill->bill_number }}</a></td><td>{{ $bill->due_date?->format('d M Y') ?: '—' }}</td><td>{{ $bill->branch->name }}</td><td class="text-end">PKR {{ \App\Services\AccountingDecimal::format($bill->report_outstanding, 2, 4) }}</td></tr>
                @endforeach
                @foreach ($obligations as $obligation)
                    <tr><td>{{ $obligation->vendor?->name ?? 'Unassigned vendor' }}</td><td><a href="{{ route('transactions.show', $obligation->sourceExpense) }}">Accrued expense #{{ $obligation->sourceExpense->id }}{{ ($obligation->sourceExpense->reference_number ?? $obligation->source_reference) ? ' - '.($obligation->sourceExpense->reference_number ?? $obligation->source_reference) : '' }}</a></td><td>{{ $obligation->obligation_date->format('d M Y') }}</td><td>{{ $obligation->branch->name }}</td><td class="text-end">PKR {{ \App\Services\AccountingDecimal::format($obligation->report_outstanding, 2, 4) }}</td></tr>
                @endforeach
                @if ($bills->isEmpty() && $obligations->isEmpty())
                    <tr><td colspan="5" class="text-center py-5">No outstanding posted payables.</td></tr>
                @endif
            </tbody>
        </table>
    </div></div></div>
@endsection
