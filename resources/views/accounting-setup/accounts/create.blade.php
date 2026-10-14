@extends('layouts.app') @section('title','Add Account') @section('eyebrow','Chart of Accounts') @section('page-title','Add Account')
@section('content')<form class="surface-card" method="POST" action="{{ route('accounts.store') }}">@csrf<div class="card-heading"><h2>Account details</h2></div><div class="form-stack">@include('accounting-setup.accounts._form')</div></form>@endsection
