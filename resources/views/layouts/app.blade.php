<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | {{ $appSettings['organization_short_name'] }} Accounting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <script>document.documentElement.dataset.sidebar = localStorage.getItem('frc-accounting-sidebar') || 'expanded';</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-page">
    <div class="app-shell">
        <aside class="app-sidebar" id="appSidebar" aria-label="Primary navigation">
            <div class="sidebar-brand">
                <img src="{{ asset($appSettings['logo_path']) }}" alt="{{ $appSettings['organization_name'] }}">
                <div class="sidebar-brand-copy">
                    <strong>{{ $appSettings['organization_short_name'] }}</strong>
                    <span>Accounting</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <span class="sidebar-label">Workspace</span>
                @can('dashboard.view')
                <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <span class="sidebar-icon" aria-hidden="true">D</span><span class="sidebar-text">Dashboard</span>
                </a>
                @endcan

                @php($directorControls = auth()->user()->can('approval_settings.manage') && app(\App\Services\ApprovalSettingsService::class)->mayManage(auth()->user()))
                @if($directorControls || auth()->user()->canAny(['users.view', 'roles.view', 'settings.view']))
                    <span class="sidebar-label">Administration</span>
                    @can('users.view')<a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}"><span class="sidebar-icon">U</span><span class="sidebar-text">Users</span></a>@endcan
                    @can('roles.view')<a href="{{ route('roles.index') }}" class="sidebar-link {{ request()->routeIs('roles.*') ? 'active' : '' }}"><span class="sidebar-icon">R</span><span class="sidebar-text">Roles & Permissions</span></a>@endcan
                    @can('settings.view')<a href="{{ route('settings.edit') }}" class="sidebar-link {{ request()->routeIs('settings.*') ? 'active' : '' }}"><span class="sidebar-icon">S</span><span class="sidebar-text">Settings</span></a>@endcan
                    @if($directorControls)<a href="{{ route('approval-settings.edit') }}" class="sidebar-link {{ request()->routeIs('approval-settings.*') ? 'active' : '' }}"><span class="sidebar-icon">AC</span><span class="sidebar-text">Approval Controls</span></a>@endif
                @endif

                @if(auth()->user()->canAny(['branches.view', 'services.view']))
                    <span class="sidebar-label">FRC Master Data</span>
                    @can('branches.view')<a href="{{ route('branches.index') }}" class="sidebar-link {{ request()->routeIs('branches.*') ? 'active' : '' }}"><span class="sidebar-icon">B</span><span class="sidebar-text">Branches</span></a>@endcan
                    @can('services.view')<a href="{{ route('services.index') }}" class="sidebar-link {{ request()->routeIs('services.*') ? 'active' : '' }}"><span class="sidebar-icon">SV</span><span class="sidebar-text">Services</span></a>@endcan
                @endif

                @if(auth()->user()->canAny(['fiscal_years.view', 'accounting_periods.view', 'accounts.view', 'voucher_types.view', 'opening_balances.view', 'account_mappings.view', 'accounting_setup.readiness']))
                    <span class="sidebar-label">Accounting Setup</span>
                    @can('fiscal_years.view')<a href="{{ route('fiscal-years.index') }}" class="sidebar-link {{ request()->routeIs('fiscal-years.*') ? 'active' : '' }}"><span class="sidebar-icon">FY</span><span class="sidebar-text">Fiscal Years</span></a>@endcan
                    @can('accounting_periods.view')<a href="{{ route('accounting-periods.index') }}" class="sidebar-link {{ request()->routeIs('accounting-periods.*') ? 'active' : '' }}"><span class="sidebar-icon">P</span><span class="sidebar-text">Accounting Periods</span></a>@endcan
                    @can('accounts.view')<a href="{{ route('accounts.index') }}" class="sidebar-link {{ request()->routeIs('accounts.*','account-imports.*') ? 'active' : '' }}"><span class="sidebar-icon">COA</span><span class="sidebar-text">Chart of Accounts</span></a>@endcan
                    @can('voucher_types.view')<a href="{{ route('voucher-types.index') }}" class="sidebar-link {{ request()->routeIs('voucher-types.*') ? 'active' : '' }}"><span class="sidebar-icon">VT</span><span class="sidebar-text">Voucher Types</span></a>@endcan
                    @can('opening_balances.view')<a href="{{ route('opening-balances.index') }}" class="sidebar-link {{ request()->routeIs('opening-balances.*','opening-balance-imports.*') ? 'active' : '' }}"><span class="sidebar-icon">OB</span><span class="sidebar-text">Opening Balances</span></a>@endcan
                    @can('account_mappings.view')<a href="{{ route('account-mappings.index') }}" class="sidebar-link {{ request()->routeIs('account-mappings.*') ? 'active' : '' }}"><span class="sidebar-icon">GM</span><span class="sidebar-text">GL Mapping</span></a>@endcan
                    @can('accounting_setup.readiness')<a href="{{ route('accounting-setup.readiness') }}" class="sidebar-link {{ request()->routeIs('accounting-setup.readiness') ? 'active' : '' }}"><span class="sidebar-icon">RD</span><span class="sidebar-text">Setup Readiness</span></a>@endcan
                @endif

                @if(auth()->user()->canAny(['journals.view', 'ledgers.view', 'trial_balance.view']))
                    <span class="sidebar-label">Accounting</span>
                    @can('journals.view')<a href="{{ route('journals.index') }}" class="sidebar-link {{ request()->routeIs('journals.*') ? 'active' : '' }}"><span class="sidebar-icon">JV</span><span class="sidebar-text">Journal Entries</span></a>@endcan
                    @can('ledgers.view')<a href="{{ route('reports.general-ledger') }}" class="sidebar-link {{ request()->routeIs('reports.general-ledger') ? 'active' : '' }}"><span class="sidebar-icon">GL</span><span class="sidebar-text">General Ledger</span></a>@endcan
                    @can('trial_balance.view')<a href="{{ route('reports.trial-balance') }}" class="sidebar-link {{ request()->routeIs('reports.trial-balance') ? 'active' : '' }}"><span class="sidebar-icon">TB</span><span class="sidebar-text">Trial Balance</span></a>@endcan
                @endif

                @if(auth()->user()->canAny(['financial_accounts.view', 'receipts.view', 'payments.view', 'expenses.view', 'vendors.view', 'purchase_orders.view', 'purchase_bills.view', 'petty_cash.view', 'donations.view']))
                    <span class="sidebar-label">Operational Finance</span>
                    @can('financial_accounts.view')<a href="{{ route('financial-accounts.index') }}" class="sidebar-link {{ request()->routeIs('financial-accounts.*') ? 'active' : '' }}"><span class="sidebar-icon">CB</span><span class="sidebar-text">Cash & Bank</span></a>@endcan
                    @can('receipts.view')<a href="{{ route('transactions.index','cash_receipt') }}" class="sidebar-link"><span class="sidebar-icon">CR</span><span class="sidebar-text">Cash Receipts</span></a><a href="{{ route('transactions.index','bank_receipt') }}" class="sidebar-link"><span class="sidebar-icon">BR</span><span class="sidebar-text">Bank Receipts</span></a>@endcan
                    @can('payments.view')<a href="{{ route('transactions.index','cash_payment') }}" class="sidebar-link"><span class="sidebar-icon">CP</span><span class="sidebar-text">Cash Payments</span></a><a href="{{ route('transactions.index','bank_payment') }}" class="sidebar-link"><span class="sidebar-icon">BP</span><span class="sidebar-text">Bank Payments</span></a>@endcan
                    @can('expenses.view')<a href="{{ route('transactions.index','expense') }}" class="sidebar-link"><span class="sidebar-icon">EX</span><span class="sidebar-text">Expenses</span></a>@endcan
                    @can('vendors.view')<a href="{{ route('vendors.index') }}" class="sidebar-link {{ request()->routeIs('vendors.*') ? 'active' : '' }}"><span class="sidebar-icon">V</span><span class="sidebar-text">Vendors</span></a>@endcan
                    @can('purchase_orders.view')<a href="{{ route('purchase-orders.index') }}" class="sidebar-link {{ request()->routeIs('purchase-orders.*') ? 'active' : '' }}"><span class="sidebar-icon">PO</span><span class="sidebar-text">Purchase Orders</span></a>@endcan
                    @can('purchase_bills.view')<a href="{{ route('purchase-bills.index') }}" class="sidebar-link {{ request()->routeIs('purchase-bills.*') ? 'active' : '' }}"><span class="sidebar-icon">PB</span><span class="sidebar-text">Purchase Bills</span></a>@endcan
                    @can('vendor_payments.view')<a href="{{ route('transactions.index','vendor_payment') }}" class="sidebar-link"><span class="sidebar-icon">VP</span><span class="sidebar-text">Vendor Payments</span></a>@endcan
                    @can('petty_cash.view')<a href="{{ route('transactions.index','petty_cash') }}" class="sidebar-link"><span class="sidebar-icon">PC</span><span class="sidebar-text">Petty Cash</span></a>@endcan
                    @can('donations.view')<a href="{{ route('transactions.index','donation') }}" class="sidebar-link"><span class="sidebar-icon">DR</span><span class="sidebar-text">Donations</span></a>@endcan
                    @can('donations.manage_categories')<a href="{{ route('donation-categories.index') }}" class="sidebar-link"><span class="sidebar-icon">DC</span><span class="sidebar-text">Donation Categories</span></a>@endcan
                @endif

                @foreach(['assets', 'inventory', 'budgets', 'payroll', 'statements', 'cashflow', 'ledger-exports', 'operational-reports', 'payroll-reports', 'frc-integration', 'fee-posting', 'bank-reconciliation', 'imprest', 'funds'] as $navModule) @include('layouts.partials.nav-'.$navModule) @endforeach

                @if(auth()->user()->canAny(['cash_books.view', 'bank_books.view', 'finance_reports.view']))
                    <span class="sidebar-label">Finance Reports</span>
                    @can('cash_books.view')<a href="{{ route('reports.books','cash') }}" class="sidebar-link"><span class="sidebar-icon">CB</span><span class="sidebar-text">Cash Book</span></a>@endcan
                    @can('bank_books.view')<a href="{{ route('reports.books','bank') }}" class="sidebar-link"><span class="sidebar-icon">BB</span><span class="sidebar-text">Bank Book</span></a>@endcan
                    @can('finance_reports.view')<a href="{{ route('reports.registers','receipts') }}" class="sidebar-link"><span class="sidebar-icon">RR</span><span class="sidebar-text">Receipt Register</span></a><a href="{{ route('reports.registers','payments') }}" class="sidebar-link"><span class="sidebar-icon">PR</span><span class="sidebar-text">Payment Register</span></a><a href="{{ route('reports.registers','expenses') }}" class="sidebar-link"><span class="sidebar-icon">ER</span><span class="sidebar-text">Expense Register</span></a><a href="{{ route('reports.registers','donations') }}" class="sidebar-link"><span class="sidebar-icon">DR</span><span class="sidebar-text">Donation Register</span></a><a href="{{ route('reports.payables') }}" class="sidebar-link"><span class="sidebar-icon">AP</span><span class="sidebar-text">Payables & Aging</span></a>@endcan
                @endif
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-footer-mark">{{ $appSettings['currency_code'] }}</div>
                <div class="sidebar-footer-copy"><strong>Single FRC</strong><span>Multiple branches</span></div>
            </div>
        </aside>

        <div class="sidebar-backdrop" data-sidebar-close></div>

        <div class="app-main">
            <header class="app-topbar">
                <div class="topbar-start">
                    <button class="icon-button sidebar-mobile-toggle" type="button" data-sidebar-mobile aria-label="Open navigation">
                        <span></span><span></span><span></span>
                    </button>
                    <button class="icon-button sidebar-collapse-toggle" type="button" data-sidebar-collapse aria-label="Collapse navigation">
                        <span class="collapse-chevron">‹</span>
                    </button>
                    <div class="topbar-title">
                        <span>{{ $appSettings['organization_short_name'] }}</span>
                        <strong>Accounting Software</strong>
                    </div>
                </div>

                <div class="dropdown">
                    <button class="user-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="user-copy"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->roles->pluck('name')->join(', ') ?: 'Accounting user' }}</small></span>
                        <span aria-hidden="true">⌄</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('profile.edit') }}">Profile & password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item text-danger" type="submit">Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </header>

            <main class="app-content">
                @if (session('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="status">
                        {{ session('status') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <strong>Please review the highlighted information.</strong>
                        <ul class="mb-0 mt-1">
                            @foreach (collect($errors->all())->unique()->take(8) as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="page-header">
                    <div>
                        <p class="page-eyebrow">@yield('eyebrow', 'Accounting workspace')</p>
                        <h1>@yield('page-title', 'Dashboard')</h1>
                        @hasSection('page-description')<p class="page-description">@yield('page-description')</p>@endif
                    </div>
                    <div class="page-actions">@yield('page-actions')</div>
                </div>

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
