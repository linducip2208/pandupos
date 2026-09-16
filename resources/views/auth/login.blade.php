@extends('layouts.public')
@section('title', 'Masuk — PanduPOS')
@section('description', 'Masuk ke dashboard PanduPOS untuk mengelola transaksi, stok, pembelian, dan laporan bisnis.')
@section('content')
<section class="login-shell">
    <div class="login-brand-panel">
        <div class="login-orb orb-one"></div><div class="login-orb orb-two"></div>
        <a href="{{ route('home') }}" class="brand brand-light"><span class="brand-mark">P</span><span>PanduPOS</span></a>
        <div class="login-promise"><span class="eyebrow">KENDALI OPERASIONAL</span><h1>Semua angka penting, selalu dalam jangkauan.</h1><p>Dari transaksi pertama pagi ini sampai valuasi stok seluruh gudang.</p><div class="login-benefits"><article><strong>POS</strong><small>Checkout cepat</small></article><article><strong>Stok</strong><small>Ledger akurat</small></article><article><strong>Audit</strong><small>Jejak lengkap</small></article></div></div>
        <small>© {{ date('Y') }} PanduPOS · Powered by Laravel</small>
    </div>
    <div class="login-form-panel"><div class="login-form-wrap"><a class="mobile-login-brand brand" href="{{ route('home') }}"><span class="brand-mark">P</span><span>PanduPOS</span></a><h1>Masuk</h1><p class="login-intro">Gunakan akun bisnis atau kredensial demo Anda.</p>
        @if($errors->any())<div class="login-alert" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('login.attempt') }}">@csrf
            <label>Email<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            <label class="remember"><input type="checkbox" name="remember" value="1"> Ingat saya di perangkat ini</label>
            <button class="login-submit" type="submit">Masuk ke Dashboard</button>
        </form>
        <div class="login-divider"><span>atau gunakan demo</span></div>
        <div class="demo-box"><strong>🧪 Akun Demo</strong><code>Owner: owner@demo.local / password</code><code>Manager: manager@demo.local / password</code><code>Kasir: cashier@demo.local / password</code><code>Gudang: warehouse@demo.local / password</code><code>Purchasing: purchasing@demo.local / password</code><code>Sales: sales@demo.local / password</code><code>Pelanggan: customer@demo.local / password (di /portal)</code><code>Platform Admin: diatur lewat environment</code></div>
    </div></div>
</section>
@endsection
