@extends('layouts.tabler')
@section('title', 'PanduPOS Enterprise — POS, Inventory & Bisnis Platform')
@section('header', 'PanduPOS Enterprise')
@section('content')
<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Platform bisnis modular baru — bukan UltimatePOS</h2>
                <p class="text-secondary">POS • Inventory • Purchasing • Sales • SaaS Billing • API v1 • Offline-ready • Tabler UI • Dark-mode ready</p>
                <a href="{{ route('login') }}" class="btn btn-primary">Masuk Dashboard</a>
                <a href="{{ route('pos.index') }}" class="btn btn-outline-primary ms-2">Buka Kasir POS</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Isolasi Tenant</div><div class="h1">Scope + Middleware</div><div class="text-secondary">Test hijau 15/15</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Stok</div><div class="h1">Ledger Immutable</div><div class="text-secondary">Tolak oversell</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Bayar</div><div class="h1">Idempotent</div><div class="text-secondary">Split cash/QRIS/transfer</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">SaaS</div><div class="h1">Plan + Entitlement</div><div class="text-secondary">Usage limits</div></div></div></div>
</div>
@endsection
