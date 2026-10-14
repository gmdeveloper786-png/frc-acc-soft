@extends('layouts.app') @section('title','Edit Payroll Component') @section('eyebrow','Payroll') @section('page-title','Edit '.$component->name)
@section('page-description','Code '.$component->code.'. The type cannot change once the component is used on a payroll run.')
@section('content')
<form method="POST" action="{{ route('payroll-components.update',$component) }}" data-submit-once>@csrf @method('PUT')
<section class="surface-card">
    <div class="card-heading"><div><span class="section-kicker">Configuration</span><h2>Component details</h2></div></div>
    @include('payroll.components._fields')
    <div class="form-actions"><button class="btn btn-primary">Save changes</button><a class="btn btn-outline-primary" href="{{ route('payroll-components.index') }}">Cancel</a></div>
</section>
</form>
@endsection
