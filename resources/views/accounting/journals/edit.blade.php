@extends('layouts.app')
@section('title','Edit Journal')
@section('page-title','Edit Draft Journal')
@section('page-description','Submitted and posted journals cannot be silently edited.')
@section('content')
@include('accounting.journals._form')
@endsection
