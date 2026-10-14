@extends('layouts.app') @section('title', 'Edit Role') @section('eyebrow', 'Access management') @section('page-title', 'Edit Role') @section('page-description', 'Update '.$role->name.' permissions.')
@section('content')<form class="surface-card" method="POST" action="{{ route('roles.update', $role) }}">@csrf @method('PUT') @include('administration.roles._form')</form>@endsection
