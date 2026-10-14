@extends('layouts.app') @section('title','New Employee') @section('eyebrow','Payroll') @section('page-title','New Employee')
@section('page-description','Create the employee master record. The salary structure is entered afterwards on the employee record.')
@section('content')
@include('payroll.employees._form')
@endsection
