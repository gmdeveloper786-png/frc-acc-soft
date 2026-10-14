@extends('layouts.app')
@section('title','Create Journal')
@section('page-title','Create Journal Entry')
@section('page-description','Voucher number will be assigned only after successful posting.')
@section('content')
@include('accounting.journals._form')
@endsection
