@extends('layouts.app') @section('title','Edit Employee '.$employee->employee_code) @section('eyebrow','Payroll') @section('page-title','Edit Employee '.$employee->employee_code)
@section('page-description',$employee->name)
@section('content')
@include('payroll.employees._form')
@endsection
