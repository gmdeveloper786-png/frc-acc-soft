@extends('layouts.app') @section('title','Edit '.$advance->reference) @section('eyebrow','Payroll') @section('page-title','Edit '.$advance->reference)
@section('page-description','Saving a draft regenerates its recovery schedule.')
@section('content')
@include('payroll.advances._form')
@endsection
