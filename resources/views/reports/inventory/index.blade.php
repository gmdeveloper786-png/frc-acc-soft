@extends('layouts.app') @section('title','Inventory Reports') @section('eyebrow','Operational Reports') @section('page-title','Inventory Reports')
@section('page-description','Read-only, quantity-only reports from posted stock movements. Stock is not valued and no report writes stock or posts a journal.')
@section('content')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Reports</span><h2>Choose a report</h2></div></div>
    <div class="table-responsive"><table class="table accounting-table"><thead><tr><th>Report</th><th>What it shows</th><th></th></tr></thead><tbody>
    @foreach($reports as $key => $definition)
        <tr><td><strong>{{ $definition['title'] }}</strong></td><td>{{ $definition['description'] }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('reports.inventory.'.$key) }}">Open</a></td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endsection
