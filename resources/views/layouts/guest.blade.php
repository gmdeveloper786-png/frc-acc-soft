<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign in') | {{ $appSettings['organization_short_name'] }} Accounting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-brand-panel" aria-label="{{ $appSettings['organization_name'] }}">
            <div class="auth-brand-content">
                <div class="auth-logo-wrap">
                    <img src="{{ asset($appSettings['logo_path']) }}" alt="{{ $appSettings['organization_name'] }}" class="auth-logo">
                </div>
                <span class="auth-eyebrow">Finance with clarity</span>
                <h1>Responsible accounting for meaningful care.</h1>
                <p>A secure financial workspace for {{ $appSettings['organization_name'] }}.</p>
                <div class="auth-trust-row" aria-label="System qualities">
                    <span>Secure</span><span>Auditable</span><span>Branch-aware</span>
                </div>
            </div>
            <div class="auth-brand-footer">{{ $appSettings['organization_name'] }}</div>
        </section>

        <section class="auth-form-panel">
            <div class="auth-mobile-brand">
                <img src="{{ asset($appSettings['logo_path']) }}" alt="{{ $appSettings['organization_name'] }}">
            </div>
            <div class="auth-card">
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
            <p class="auth-help">Authorized accounting users only. Contact your administrator for access.</p>
        </section>
    </main>
</body>
</html>
