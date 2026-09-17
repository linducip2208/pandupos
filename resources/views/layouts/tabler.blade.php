<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>@yield('title', 'PanduPOS Enterprise')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell">
<div class="page">
    <aside id="admin-sidebar" class="navbar navbar-vertical admin-sidebar" data-bs-theme="dark">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <h1 class="navbar-brand navbar-brand-autodark">
                <a href="{{ url('/') }}">PanduPOS</a>
            </h1>
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav pt-lg-3">
                    <li class="nav-group"><button class="nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#nav-transactions" aria-expanded="true"><x-nav-icon name="transaction-group"/><span>🧾 Transaksi</span><span class="nav-chevron">⌄</span></button><ul class="collapse show nav-submenu" id="nav-transactions">
                        <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}"><x-nav-icon name="dashboard"/><span class="nav-link-title">Dashboard</span></a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('pos.index') }}"><x-nav-icon name="pos"/><span class="nav-link-title">POS Kasir</span></a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('sales-orders.index') }}"><x-nav-icon name="sales-order"/><span class="nav-link-title">Sales Order</span></a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('approvals.index') }}"><x-nav-icon name="approval"/><span class="nav-link-title">Approval</span></a></li>
                    </ul></li>
                    @can('inventory.view')
                        <li class="nav-group"><button class="nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#nav-inventory" aria-expanded="true"><x-nav-icon name="inventory-group"/><span>📦 Persediaan</span><span class="nav-chevron">⌄</span></button><ul class="collapse show nav-submenu" id="nav-inventory">
                            <li class="nav-item"><a class="nav-link" href="{{ route('product-master.index') }}"><x-nav-icon name="product-master"/><span class="nav-link-title">Master Produk</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('barcodes.index') }}"><x-nav-icon name="barcode"/><span class="nav-link-title">Barcode & Label</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('price-lists.index') }}"><x-nav-icon name="price-list"/><span class="nav-link-title">Daftar Harga</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('bundles.index') }}"><x-nav-icon name="bundle"/><span class="nav-link-title">Bundle & Combo</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('batches.index') }}"><x-nav-icon name="batch"/><span class="nav-link-title">Batch & Kedaluwarsa</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ route('inventory.index') }}"><x-nav-icon name="inventory-control"/><span class="nav-link-title">Kontrol Persediaan</span></a></li>
                        </ul></li>
                    @endcan
                    @if(auth()->user()?->can('purchase.create') || auth()->user()?->can('purchase.approve'))
                        <li class="nav-group"><button class="nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#nav-purchasing" aria-expanded="true"><x-nav-icon name="purchasing-group"/><span>🛒 Pembelian</span><span class="nav-chevron">⌄</span></button><ul class="collapse show nav-submenu" id="nav-purchasing">
                            <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.index') }}"><x-nav-icon name="purchasing-workspace"/><span class="nav-link-title">Purchasing Workspace</span></a></li>
                        </ul></li>
                    @endif
                    <li class="nav-group"><button class="nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#nav-reports" aria-expanded="true"><x-nav-icon name="report-group"/><span>📊 Laporan</span><span class="nav-chevron">⌄</span></button><ul class="collapse show nav-submenu" id="nav-reports">
                        <li class="nav-item"><a class="nav-link" href="{{ route('reports.show','bisnis') }}"><x-nav-icon name="business"/><span class="nav-link-title">Bisnis Utama</span></a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('reports.show','keuangan') }}"><x-nav-icon name="finance"/><span class="nav-link-title">Keuangan</span></a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('reports.show','operasional') }}"><x-nav-icon name="operations"/><span class="nav-link-title">Operasional</span></a></li>
                    </ul></li>
                    @auth
                        @if(auth()->user()->is_platform_admin)
                            <li class="nav-group"><button class="nav-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#nav-platform" aria-expanded="false"><x-nav-icon name="platform-group"/><span>⚙️ Platform</span><span class="nav-chevron">⌄</span></button><ul class="collapse nav-submenu" id="nav-platform">
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.dashboard') }}"><x-nav-icon name="platform-dashboard"/><span class="nav-link-title">Platform Dashboard</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.tenants.index') }}"><x-nav-icon name="tenants"/><span class="nav-link-title">Tenant</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.plans.index') }}"><x-nav-icon name="plans"/><span class="nav-link-title">Paket</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.modules.index') }}"><x-nav-icon name="modules"/><span class="nav-link-title">Modul</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.audit.index') }}"><x-nav-icon name="audit"/><span class="nav-link-title">Audit</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.blog.index') }}"><x-nav-icon name="blog"/><span class="nav-link-title">Blog</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.integrations.index') }}"><x-nav-icon name="integrations"/><span class="nav-link-title">Integrasi</span></a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ route('platform.health') }}"><x-nav-icon name="health"/><span class="nav-link-title">Kesehatan Sistem</span></a></li>
                            </ul></li>
                        @endif
                    @endauth
                </ul>
            </div>
        </div>
    </aside>
    <button class="admin-sidebar-overlay" type="button" aria-label="Tutup navigasi" onclick="document.body.classList.remove('sidebar-open')"></button>
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
            <button class="admin-menu-toggle" type="button" aria-label="Buka navigasi" onclick="document.body.classList.toggle('sidebar-open')"><span></span><span></span><span></span></button>
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
<script>document.querySelectorAll('#admin-sidebar a').forEach(link=>link.addEventListener('click',()=>document.body.classList.remove('sidebar-open')));</script>
</body>
</html>
