@extends('layouts.guest')

@section('title', 'Forgot password')

@section('content')
    <a class="auth-back" href="{{ route('login') }}">← Back to sign in</a>
    <div class="auth-heading">
        <span class="auth-kicker">Account recovery</span>
        <h2>Reset your password</h2>
        <p>Enter your work email. If an active account exists, we will send a secure reset link.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="auth-form">
        @csrf
        <div>
            <label class="form-label" for="email">Email address</label>
            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" autofocus required>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Send reset link</button>
    </form>
@endsection
