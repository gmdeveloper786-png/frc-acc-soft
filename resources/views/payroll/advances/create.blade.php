@extends('layouts.app') @section('title','New Staff Advance') @section('eyebrow','Payroll') @section('page-title','New Staff Advance')
@section('page-description','Record an advance or loan and generate its recovery schedule. It is paid out through the payment workflow after approval.')
@section('content')
@include('payroll.advances._form')
@endsection
