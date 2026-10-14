@extends('layouts.app') @section('title','New Inventory Item') @section('eyebrow','Inventory') @section('page-title','New Inventory Item')
@section('page-description','A new item holds no stock until a receipt is recorded for a branch location.')
@section('content')
@if($categories->isEmpty())<div class="alert alert-warning">No active category exists yet. <a href="{{ route('inventory.categories.index') }}">Add a category</a> first.</div>@endif
@include('inventory.items._form')
@endsection
