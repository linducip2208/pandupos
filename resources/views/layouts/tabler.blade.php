<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>@yield('title', 'PanduPOS Enterprise')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<div class="page">
    <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <h1 class="navbar-brand navbar-brand-autodark">
                <a href="{{ url('/') }}">PanduPOS</a>
            </h1>
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav pt-lg-3">
                    <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('pos.index') }}">POS Kasir</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ url('/api/v1/reports/sales?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString()) }}">Laporan</a></li>
                    @auth
                        @if(auth()->user()->is_platform_admin)
                            <li class="nav-item mt-2"><span class="nav-link text-muted">PLATFORM ADMIN</span></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('platform.dashboard') }}">Platform Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('platform.tenants.index') }}">Tenants</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('platform.plans.index') }}">Plans</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('platform.modules.index') }}">Modules</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('platform.audit.index') }}">Audit</a></li>
                        @endif
                    @endauth
                    <li class="nav-item"><a class="nav-link" href="{{ route('platform.health') }}">System Health</a></li>
                </ul>
            </div>
        </div>
    </aside>
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
            <h1 class="navbar-brand">@yield('header', 'Dashboard')</h1>
            <div class="navbar-nav flex-row order-md-last">
                <button class="btn btn-icon" onclick="document.documentElement.dataset.bsTheme=document.documentElement.dataset.bsTheme==='dark'?'light':'dark'" title="Dark mode">◐</button>
                @auth
                    <span class="nav-link">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-link nav-link">Keluar</button></form>
                @else
                    <a class="nav-link" href="{{ route('login') }}">Masuk</a>
                @endauth
            </div>
        </div>
    </header>
    <div class="page-wrapper">
        <div class="page-body">
            <div class="container-xl">
                @if(session('impersonating') || session()->has('impersonating'))
                    @php $imp = session('impersonating'); @endphp
                    <div class="alert alert-warning d-flex justify-content-between align-items-center">
                        <span>Impersonating: {{ $imp['tenant_name'] ?? 'tenant' }}</span>
                        <form method="POST" action="{{ route('platform.impersonation.stop') }}">@csrf<button class="btn btn-sm btn-dark">Exit Impersonation</button></form>
                    </div>
                @endif
                @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
                @yield('content')
            </div>
        </div>
    </div>
</div>
@livewireScripts
@stack('scripts')
</body>
</html>
