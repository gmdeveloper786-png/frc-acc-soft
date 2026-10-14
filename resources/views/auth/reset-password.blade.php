@extends('layouts.guest')

@section('title', 'Choose new password')

@section('content')
    <div class="auth-heading">
        <span class="auth-kicker">Secure your account</span>
        <h2>Choose a new password</h2>
        <p>Use a strong password that is unique to this accounting system.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="auth-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label class="form-label" for="email">Email address</label>
            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" readonly required>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="password">New password</label>
            <div class="password-field">
                <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="new-password" required>
                <button type="button" data-password-toggle="password" aria-label="Show password">Show</button>
            </div>
            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div>
            <label class="form-label" for="password_confirmation">Confirm new password</label>
            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Reset password</button>
    </form>
@endsection
