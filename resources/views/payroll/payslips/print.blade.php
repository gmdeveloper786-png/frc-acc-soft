@extends('layouts.app') @section('title',($payslips->count() === 1 ? 'Payslip ' : 'Payslips ').$run->period->name) @section('eyebrow','Payroll') @section('page-title',$payslips->count() === 1 ? 'Payslip' : 'Payslips')
@section('page-description',$run->period->name.' • '.$run->branch->name.' • Run #'.$run->id.' • '.$payslips->count().' '.\Illuminate\Support\Str::plural('employee',$payslips->count()))
@section('page-actions')
<div class="d-flex gap-2 d-print-none">
    <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
    <a class="btn btn-outline-primary" href="{{ route('payroll-runs.show',$run) }}">Back to run</a>
</div>
@endsection
@section('content')
<style>
    .payslip + .payslip { margin-top: 1.5rem; }
    @media print { .payslip { break-after: page; page-break-after: always; } .payslip:last-child { break-after: auto; page-break-after: auto; } }
</style>
@forelse($payslips as $slip)
    @php
        $employee = $slip['line']->employee;
        $rows = max(count($slip['earnings']), count($slip['deductions']));
    @endphp
    <section class="surface-card payslip">
        <div class="card-heading">
            <div class="d-flex align-items-center gap-3">
                @if(!empty($appSettings['logo_path']))<img src="{{ asset($appSettings['logo_path']) }}" alt="{{ $appSettings['organization_name'] }}" style="max-height:56px">@endif
                <div><span class="section-kicker">Confidential — payslip</span><h2>{{ $appSettings['organization_name'] }}</h2></div>
            </div>
            <span class="status-badge {{ $run->status->badge() }}">{{ $paymentStatus }}</span>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><small>Pay period</small><strong class="d-block">{{ $run->period->name }}</strong><span class="table-subtext">{{ $run->period->start_date->format('d M Y') }} – {{ $run->period->end_date->format('d M Y') }}</span></div>
            <div class="col-md-3"><small>Employee</small><strong class="d-block">{{ $employee->name }}</strong><span class="table-subtext">Code {{ $employee->employee_code }}</span></div>
            <div class="col-md-3"><small>Designation</small><strong class="d-block">{{ $employee->designation ?: '—' }}</strong></div>
            <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $run->branch->name }}</strong><span class="table-subtext">Payroll run #{{ $run->id }}</span></div>
        </div>
        <div class="table-responsive"><table class="table accounting-table">
            <thead><tr><th>Earnings</th><th class="text-end">Amount</th><th>Deductions</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @for($index = 0; $index < max($rows, 1); $index++)
                <tr>
                    <td>{{ $slip['earnings'][$index]['name'] ?? '' }}</td>
                    <td class="text-end font-monospace">{{ isset($slip['earnings'][$index]) ? number_format((float) $slip['earnings'][$index]['amount'], 2) : '' }}</td>
                    <td>{{ $slip['deductions'][$index]['name'] ?? ($index === 0 && $slip['deductions'] === [] ? 'No deductions' : '') }}</td>
                    <td class="text-end font-monospace">{{ isset($slip['deductions'][$index]) ? number_format((float) $slip['deductions'][$index]['amount'], 2) : '' }}</td>
                </tr>
            @endfor
            </tbody>
            <tfoot>
                <tr><th>Gross pay</th><th class="text-end font-monospace">{{ number_format((float) $slip['gross'], 2) }}</th><th>Total deductions</th><th class="text-end font-monospace">{{ number_format((float) $slip['deductions_total'], 2) }}</th></tr>
                <tr><th colspan="3" class="text-end">Net pay ({{ $appSettings['currency_code'] }})</th><th class="text-end font-monospace"><strong>{{ number_format((float) $slip['net'], 2) }}</strong></th></tr>
            </tfoot>
        </table></div>
        <div class="row g-3 mt-2">
            <div class="col-md-4"><small>Payment status</small><strong class="d-block">{{ $paymentStatus }}</strong></div>
            <div class="col-md-4"><small>Payroll run status</small><strong class="d-block">{{ $run->status->label() }}</strong></div>
            <div class="col-md-4"><small>Printed</small><strong class="d-block">{{ now()->format('d M Y H:i') }}</strong></div>
        </div>
        <p class="card-copy mb-0">Stored amounts of the approved payroll run. Tax, leave and statutory amounts were entered or imported by an authorised user; the system applies no rate or formula.</p>
    </section>
@empty
    <section class="surface-card"><x-empty-state title="No payslips" description="This payroll run has no employee lines." /></section>
@endforelse
@endsection
