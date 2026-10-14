@extends('layouts.app') @section('title','Edit Budget '.$budget->name) @section('eyebrow','Budget management') @section('page-title','Edit Budget')
@section('page-description',$budget->name.' • v'.$budget->version.' • '.$budget->fiscalYear->name.' • '.$budget->branch->name)
@section('content')
@include('budgets._form')
@endsection
