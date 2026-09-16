<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Portal Pelanggan') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="portal-site">
<header class="portal-header">
    <div class="portal-container portal-header-inner">
        <a class="portal-brand" href="{{ route('portal.dashboard') }}">PanduPOS <small>Portal</small></a>
        @auth('customer')
            <button class="portal-menu-button" type="button" onclick="document.querySelector('.portal-nav').classList.toggle('is-open')" aria-label="Buka navigasi">☰</button>
            <nav class="portal-nav">
                <a href="{{ route('portal.dashboard') }}">Dashboard</a>
                <a href="{{ route('portal.orders.index') }}">Pesanan saya</a>
                <a href="{{ route('portal.invoices.index') }}">Invoice</a>
                <form method="POST" action="{{ route('portal.logout') }}">@csrf<button type="submit">Keluar</button></form>
            </nav>
        @endauth
    </div>
</header>
<main class="portal-container portal-main">
    @if(session('status'))<div class="portal-alert">{{ session('status') }}</div>@endif
    @yield('content')
</main>
</body>
</html>
