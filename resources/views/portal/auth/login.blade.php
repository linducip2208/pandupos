@extends('portal.layout')
@section('title', 'Masuk Portal Pelanggan')
@section('content')
<div class="portal-auth-grid">
    <section class="portal-auth-story">
        <span class="eyebrow">PORTAL PELANGGAN</span>
        <h1>Pesanan dan invoice Anda, selalu mudah dipantau.</h1>
        <p>Lihat riwayat transaksi, unduh invoice PDF, dan kirim bukti pembayaran tanpa harus menunggu balasan admin toko.</p>
        <div class="portal-benefits"><span>✓ Data khusus akun Anda</span><span>✓ Invoice siap diunduh</span><span>✓ Bukti bayar aman</span></div>
    </section>
    <section class="portal-login-card">
        <h2>Masuk</h2><p>Gunakan akun yang diberikan oleh toko.</p>
        @if($errors->any())<div class="portal-error">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('portal.login.attempt') }}">@csrf
            <label>Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
            <label>Kata sandi<input type="password" name="password" required autocomplete="current-password"></label>
            <label class="portal-check"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
            <button class="portal-primary" type="submit">Masuk ke portal</button>
        </form>
        <div class="demo-box"><strong>🧪 Demo Pelanggan</strong><code>customer@demo.local / password</code></div>
    </section>
</div>
@endsection
