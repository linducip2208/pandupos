<!DOCTYPE html>
<html lang="id" x-data="{ menu: false }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'PanduPOS — Operasional Ritel dalam Satu Sistem')</title>
    <meta name="description" content="@yield('description', 'PanduPOS menyatukan kasir, stok, pembelian, laporan, dan pengelolaan bisnis multi-cabang.')">
    <link rel="canonical" href="@yield('canonical', url()->current())">
    <meta property="og:title" content="@yield('title', 'PanduPOS Enterprise')">
    <meta property="og:description" content="@yield('description', 'Platform POS dan inventory modular untuk bisnis Indonesia.')">
    <meta property="og:url" content="@yield('canonical', url()->current())">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', 'PanduPOS Enterprise')">
    <meta name="twitter:description" content="@yield('description', 'Platform POS dan inventory modular untuk bisnis Indonesia.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @stack('head')
</head>
<body class="public-site">
<header class="public-header">
    <div class="public-container public-nav">
        <a href="{{ route('home') }}" class="brand" aria-label="PanduPOS beranda"><span class="brand-mark">P</span><span>PanduPOS</span></a>
        <button class="menu-button" @click="menu = !menu" :aria-expanded="menu" aria-label="Buka navigasi">☰</button>
        <nav :class="menu ? 'is-open' : ''" aria-label="Navigasi utama">
            <a href="{{ route('home') }}#fitur">Fitur</a>
            <a href="{{ route('docs') }}">Dokumentasi</a>
            <a href="{{ route('blog.index') }}">Blog</a>
            <a href="{{ route('login') }}" class="nav-cta">Coba Demo</a>
        </nav>
    </div>
</header>
<main>@yield('content')</main>
<footer class="public-footer">
    <div class="public-container footer-grid">
        <div><a href="{{ route('home') }}" class="brand brand-light"><span class="brand-mark">P</span><span>PanduPOS</span></a><p>POS, inventory, dan operasi bisnis yang tumbuh bersama usaha Anda.</p></div>
        <div><strong>Produk</strong><a href="{{ route('home') }}#fitur">Fitur</a><a href="{{ route('docs') }}">Dokumentasi</a><a href="{{ route('login') }}">Demo</a></div>
        <div><strong>Konten</strong><a href="{{ route('blog.index') }}">Blog</a><a href="{{ route('blog.feed') }}">RSS Feed</a><a href="{{ route('sitemap') }}">Sitemap</a></div>
    </div>
</footer>
<x-purchase-cta />
<script>
document.addEventListener('DOMContentLoaded', () => {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        }
    }), {threshold: .12});
    document.querySelectorAll('.reveal').forEach(element => observer.observe(element));
});
</script>
@stack('scripts')
</body>
</html>
