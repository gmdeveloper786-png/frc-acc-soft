@extends('layouts.app') @section('title','New Purchase Order') @section('eyebrow','Procurement') @section('page-title','New Purchase Order')
@section('page-description','Saved as a draft. Approval follows the configured threshold and is never automatic.')
@section('content')
@include('accounting.purchase-orders._form')
@endsection
