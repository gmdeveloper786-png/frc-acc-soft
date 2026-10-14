@extends('layouts.app') @section('title','Payroll Reports') @section('eyebrow','Payroll reports') @section('page-title','Payroll Reports')
@section('page-description','Read-only reports of stored payroll amounts. They post nothing and calculate no tax, statutory or leave figure.')
@section('content')
@unless($sensitive)
    <div class="alert alert-warning" role="alert"><strong>Salary amounts are hidden.</strong> Your account does not hold the sensitive payroll permission, so reports show workflow information only and reports that consist of amounts are not available.</div>
@endunless
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Limited to the branches you can access</span><h2>Available reports</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Report</th><th>Content</th><th>Access</th></tr></thead><tbody>
    @foreach($reports as $report)
        <tr>
            <td>@if($report['available'])<a href="{{ route('payroll-reports.show', $report['key']) }}">{{ $report['title'] }}</a>@else{{ $report['title'] }}@endif</td>
            <td>{{ $report['amountOnly'] ? 'Salary amounts only' : 'Workflow information; amounts with the sensitive payroll permission' }}</td>
            <td><span class="status-badge {{ $report['available'] ? 'success' : 'neutral' }}">{{ $report['available'] ? 'Available' : 'Needs the sensitive payroll permission' }}</span></td>
        </tr>
    @endforeach
    </tbody></table></div>
</section>
@endsection
