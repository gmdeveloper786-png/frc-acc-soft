@extends('layouts.app') @section('title','New Fiscal Year') @section('eyebrow','Accounting Setup') @section('page-title','New Fiscal Year')
@section('page-description','Monthly accounting periods will be generated from these dates.')
@section('content')<form method="POST" action="{{ route('fiscal-years.store') }}" class="surface-card">@csrf <div class="card-heading"><h2>Fiscal year details</h2></div><div class="form-stack">@include('accounting-setup.fiscal-years._form')</div></form>@endsection
