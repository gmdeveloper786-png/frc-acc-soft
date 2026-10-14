@extends('layouts.app') @section('title','Payroll Register '.$run->period->name) @section('eyebrow','Payroll') @section('page-title','Payroll Register')
@section('page-description',$run->period->name.' • '.$run->branch->name.' • '.$run->status->label())
@section('page-actions')
<div class="d-flex gap-2 d-print-none">
    <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
    <a class="btn btn-outline-primary" href="{{ route('payroll-runs.show',$run) }}">Back to run</a>
</div>
@endsection
@section('content')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Confidential — salary information</span><h2>{{ $appSettings['organization_short_name'] }} — payroll register, {{ $run->period->name }}</h2></div><span class="status-badge {{ $run->status->badge() }}">{{ $run->status->label() }}</span></div>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><small>Branch</small><strong class="d-block">{{ $run->branch->name }}</strong></div>
        <div class="col-md-3"><small>Period</small><strong class="d-block">{{ $run->period->start_date->format('d M Y') }} – {{ $run->period->end_date->format('d M Y') }}</strong></div>
        <div class="col-md-3"><small>Run</small><strong class="d-block">#{{ $run->id }}</strong></div>
        <div class="col-md-3"><small>Accrual voucher</small><strong class="d-block">{{ $run->journalEntry?->voucher_number ?? 'Not posted' }}</strong></div>
    </div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>#</th><th>Code</th><th>Employee</th>@foreach($components as $component)<th class="text-end">{{ $component->name }}</th>@endforeach<th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net</th></tr></thead><tbody>
    @forelse($run->lines as $line)
        @php
            $itemsByComponent = $line->items->keyBy('payroll_component_id');
        @endphp
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td><code>{{ $line->employee->employee_code }}</code></td>
            <td>{{ $line->employee->name }}</td>
            @foreach($components as $component)<td class="text-end font-monospace">{{ $itemsByComponent->has($component->id) ? number_format((float)$itemsByComponent->get($component->id)->amount,2) : '—' }}</td>@endforeach
            <td class="text-end font-monospace">{{ number_format((float)$line->gross_amount,2) }}</td>
            <td class="text-end font-monospace">{{ number_format((float)$line->total_deductions,2) }}</td>
            <td class="text-end font-monospace"><strong>{{ number_format((float)$line->net_amount,2) }}</strong></td>
        </tr>
    @empty
        <tr><td colspan="{{ $components->count() + 6 }}"><x-empty-state title="No payroll lines" description="This run has no employees." /></td></tr>
    @endforelse
    </tbody>
    @if($run->lines->isNotEmpty())
    <tfoot><tr><th colspan="3" class="text-end">Totals</th>@foreach($components as $component)<th class="text-end font-monospace">{{ number_format((float)$componentTotals[$component->id],2) }}</th>@endforeach<th class="text-end font-monospace">{{ number_format((float)$run->gross_amount,2) }}</th><th class="text-end font-monospace">{{ number_format((float)$run->total_deductions,2) }}</th><th class="text-end font-monospace">{{ number_format((float)$run->net_amount,2) }}</th></tr></tfoot>
    @endif
    </table></div>
    <div class="row g-3 mt-4">
        <div class="col-md-4"><small>Prepared by</small><strong class="d-block">{{ $run->creator?->name ?? '—' }}</strong></div>
        <div class="col-md-4"><small>Approved by</small><strong class="d-block">{{ $run->approver?->name ?? '—' }}</strong><span class="table-subtext">{{ $run->approved_at?->format('d M Y H:i') }}</span></div>
        <div class="col-md-4"><small>Printed</small><strong class="d-block">{{ now()->format('d M Y H:i') }}</strong></div>
    </div>
</section>
@endsection
