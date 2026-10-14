@extends('layouts.app') @section('title', 'Edit User') @section('eyebrow', 'Access management') @section('page-title', 'Edit User') @section('page-description', 'Update profile and access for '.$user->name.'.')
@section('content')<form class="surface-card" method="POST" action="{{ route('users.update', $user) }}">@csrf @method('PUT') @include('administration.users._form')</form>@endsection
