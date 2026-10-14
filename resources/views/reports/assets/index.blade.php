@extends('layouts.app') @section('title','Fixed Asset Reports') @section('eyebrow','Operational Reports') @section('page-title','Fixed Asset Reports')
@section('page-description','Read-only reports from the asset register and posted depreciation runs. No report posts a journal or changes an asset.')
@section('content')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Reports</span><h2>Choose a report</h2></div></div>
    @if($reports === [])
        <x-empty-state title="No report available" description="You do not hold a permission for any fixed asset report." />
    @else
        <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Report</th><th>What it shows</th><th></th></tr></thead><tbody>
        @foreach($reports as $key => $definition)
            <tr><td><strong>{{ $definition['title'] }}</strong></td><td>{{ $definition['description'] }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('reports.assets.'.$key) }}">Open</a></td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</section>
@endsection
