@extends('layouts.app') @section('title','Edit Purchase Order') @section('eyebrow','Procurement') @section('page-title','Edit '.$purchaseOrder->po_number)
@section('page-description','Only a draft or returned purchase order can be edited. Saving returns it to draft.')
@section('content')
@include('accounting.purchase-orders._form')
@endsection
