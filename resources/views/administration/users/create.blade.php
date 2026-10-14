@extends('layouts.app') @section('title', 'Create User') @section('eyebrow', 'Access management') @section('page-title', 'Create User') @section('page-description', 'Create a secure accounting account and assign only required access.')
@section('content')<form class="surface-card" method="POST" action="{{ route('users.store') }}">@csrf @include('administration.users._form')</form>@endsection
