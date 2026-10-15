@extends('layouts.app') @section('title','Imprest Statement') @section('eyebrow','Petty Cash Imprest') @section('page-title','Imprest Statement')
@section('page-description', $float->label().' — custodian '.$float->custodian_name.'. Posted journal lines of the petty cash ledger in this branch only.')
@section('page-actions')
<div class="d-flex gap-2">
    @can('finance_reports.export')@include('accounting.reports._export-links', ['exportRoute' => 'imprest.statement.export', 'exportParameters' => ['float' => $float->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]])@endcan
    <a class="btn btn-outline-secondary" href="{{ route('imprest.show', $float) }}">Back to float</a>
</div>
@endsection
@section('content')
@php($currency = $appSettings['currency_code'])
@foreach(['from', 'to'] as $field) @error($field)<div class="alert alert-danger">{{ $message }}</div>@enderror @endforeach
<div class="surface-card mb-4"><form class="row g-3" method="GET">
    <div class="col-md-3"><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="{{ $from->toDateString() }}"></div>
    <div class="col-md-3"><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="{{ $to->toDateString() }}"></div>
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary">Generate</button></div>
</form></div>

<div class="kpi-grid">
    <x-kpi-card label="Opening balance" value="{{ $currency }} {{ number_format((float) $statement['opening'], 2) }}" icon="O" tone="navy" status="{{ $from->format('d M Y') }}" description="Posted balance before the period"/>
    <x-kpi-card label="Vouchers paid" value="{{ $currency }} {{ number_format((float) $statement['vouchers_total'], 2) }}" icon="V" tone="rose" status="Posted" description="Petty cash vouchers net of reversals"/>
    <x-kpi-card label="Replenishments" value="{{ $currency }} {{ number_format((float) $statement['replenishments_total'], 2) }}" icon="R" tone="teal" status="Posted" description="Other entries: Dr {{ number_format((float) $statement['other_debits'], 2) }} / Cr {{ number_format((float) $statement['other_credits'], 2) }}"/>
    <x-kpi-card label="Closing balance" value="{{ $currency }} {{ number_format((float) $statement['closing'], 2) }}" icon="C" tone="amber" status="{{ $to->format('d M Y') }}" description="Float: {{ number_format((float) $float->float_amount, 2) }}"/>
</div>

<div class="card"><div class="card-body"><div class="table-responsive"><table class="table">
    <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Narration</th><th class="text-end">Received</th><th class="text-end">Paid out</th><th class="text-end">Balance</th></tr></thead>
    <tbody>
        <tr class="fw-semibold"><td>{{ $from->format('d M Y') }}</td><td></td><td>Opening balance</td><td></td><td></td><td></td><td class="text-end font-monospace">{{ number_format((float) $statement['opening'], 2) }}</td></tr>
        @foreach($statement['rows'] as $row)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                <td>@can('journals.view')<a href="{{ route('journals.show', $row['journal_id']) }}">{{ $row['voucher'] }}</a>@else{{ $row['voucher'] }}@endcan</td>
                <td>{{ $row['kind'] }}</td><td>{{ $row['narration'] }}</td>
                <td class="text-end font-monospace">{{ (float) $row['receipt'] ? number_format((float) $row['receipt'], 2) : '' }}</td>
                <td class="text-end font-monospace">{{ (float) $row['payment'] ? number_format((float) $row['payment'], 2) : '' }}</td>
                <td class="text-end font-monospace">{{ number_format((float) $row['balance'], 2) }}</td>
            </tr>
        @endforeach
        @if($statement['rows'] === [])<tr><td colspan="7" class="text-center py-4">No posted activity in this period.</td></tr>@endif
        <tr class="fw-semibold"><td>{{ $to->format('d M Y') }}</td><td></td><td>Closing balance</td><td></td><td></td><td></td><td class="text-end font-monospace">{{ number_format((float) $statement['closing'], 2) }}</td></tr>
    </tbody>
</table></div></div></div>
@endsection
