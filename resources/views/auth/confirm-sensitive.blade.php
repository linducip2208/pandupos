@extends('layouts.public')
@section('title', 'Konfirmasi Identitas — PanduPOS')
@section('description', 'Konfirmasi password untuk tindakan administratif yang sensitif di PanduPOS.')
@section('content')
<section class="login-shell">
    <div class="login-brand-panel">
        <div class="login-orb orb-one"></div><div class="login-orb orb-two"></div>
        <a href="{{ route('home') }}" class="brand brand-light"><span class="brand-mark">P</span><span>PanduPOS</span></a>
        <div class="login-promise"><span class="eyebrow">AKSI SENSITIF</span><h1>Verifikasi sekali lagi sebelum lanjut.</h1><p>Impersonasi, lifecycle tenant, payout afiliasi, pengiriman pengumuman, dan kredensial integrasi membutuhkan konfirmasi password yang masih segar.</p></div>
        <small>© {{ date('Y') }} PanduPOS · Powered by Laravel</small>
    </div>
    <div class="login-form-panel"><div class="login-form-wrap"><a class="mobile-login-brand brand" href="{{ route('home') }}"><span class="brand-mark">P</span><span>PanduPOS</span></a><h1>Konfirmasi Identitas</h1><p class="login-intro">Masukkan password Anda untuk melanjutkan. Konfirmasi berlaku 10 menit dan tercatat di audit log.</p>
        @if($errors->any())<div class="login-alert" role="alert">{{ $errors->first() }}</div>@endif
        @if(session('status'))<div class="login-alert" role="status">{{ session('status') }}</div>@endif
        <form method="POST" action="{{ route('reauth.confirm.store') }}">@csrf
            <label>Password<input type="password" name="password" autocomplete="current-password" required autofocus></label>
            <button class="login-submit" type="submit">Konfirmasi &amp; Lanjutkan</button>
        </form>
    </div></div>
</section>
@endsection
