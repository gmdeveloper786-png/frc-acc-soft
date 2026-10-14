@extends('layouts.app')

@section('title', 'Profile')
@section('eyebrow', 'Account settings')
@section('page-title', 'Profile & Security')
@section('page-description', 'Keep your identity and password current.')

@section('content')
    <div class="content-grid two-columns">
        <section class="surface-card">
            <div class="card-heading">
                <div><span class="section-kicker">Personal details</span><h2>Profile information</h2></div>
            </div>
            <form method="POST" action="{{ route('profile.update') }}" class="form-stack">
                @csrf
                @method('PATCH')
                <div>
                    <label class="form-label" for="name">Full name</label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', auth()->user()->name) }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary align-self-start" type="submit">Save profile</button>
            </form>
        </section>

        <section class="surface-card">
            <div class="card-heading">
                <div><span class="section-kicker">Security</span><h2>Change password</h2></div>
            </div>
            <form method="POST" action="{{ route('profile.password.update') }}" class="form-stack">
                @csrf
                @method('PUT')
                <div>
                    <label class="form-label" for="current_password">Current password</label>
                    <input class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="new_password">New password</label>
                    <input class="form-control @error('password') is-invalid @enderror" id="new_password" name="password" type="password" autocomplete="new-password" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="password_confirmation">Confirm new password</label>
                    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                </div>
                <button class="btn btn-outline-primary align-self-start" type="submit">Update password</button>
            </form>
        </section>
    </div>
@endsection
