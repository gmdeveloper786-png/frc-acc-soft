@extends('layouts.app') @section('title','Edit Inventory Item') @section('eyebrow','Inventory') @section('page-title','Edit '.$item->item_code)
@section('page-description','The unit of measure cannot change once stock movements exist for the item.')
@section('content')
@include('inventory.items._form')
@endsection
