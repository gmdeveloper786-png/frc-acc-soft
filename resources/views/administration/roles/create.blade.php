@extends('layouts.app') @section('title', 'Create Role') @section('eyebrow', 'Access management') @section('page-title', 'Create Role') @section('page-description', 'Create a role and assign only the permissions it requires.')
@section('content')<form class="surface-card" method="POST" action="{{ route('roles.store') }}">@csrf @include('administration.roles._form')</form>@endsection
