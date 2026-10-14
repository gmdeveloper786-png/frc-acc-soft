@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="auth-heading">
        <span class="auth-kicker">Welcome back</span>
        <h2>Sign in to Accounting</h2>
        <p>Use your authorized FRC accounting account.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="auth-form">
        @csrf
        <div>
            <label class="form-label" for="email">Email address</label>
            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" autofocus required>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div>
            <div class="label-row">
                <label class="form-label" for="password">Password</label>
                <a href="{{ route('password.request') }}">Forgot password?</a>
            </div>
            <div class="password-field">
                <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="current-password" required>
                <button type="button" data-password-toggle="password" aria-label="Show password">Show</button>
            </div>
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <label class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span class="form-check-label">Keep me signed in on this device</span>
        </label>

        <button class="btn btn-primary btn-lg w-100" type="submit">Sign in securely</button>
    </form>
@endsection
