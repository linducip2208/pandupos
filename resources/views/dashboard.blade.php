@extends('layouts.tabler')
@section('title', 'Dashboard — PanduPOS')
@section('header', 'Dashboard')
@section('content')
<div class="row row-cards">
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Tenant</div><div class="h1">{{ $tenant->name ?? '-' }}</div><div class="text-secondary">{{ $tenant->status ?? '' }} • {{ $tenant->currency ?? '' }}</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Omzet (bulan ini)</div><div class="h1">Rp {{ number_format($sales['revenue'] ?? 0, 0, ',', '.') }}</div><div class="text-secondary">{{ $sales['invoices'] ?? 0 }} invoice</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Paket</div><div class="h1">{{ $plan->name ?? '-' }}</div><div class="text-secondary">{{ $subscription->status ?? '-' }}</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Usage Produk</div><div class="h1">{{ $usage['products.max']['used'] ?? 0 }} / {{ $usage['products.max']['limit'] ?? '∞' }}</div><div class="text-secondary">Cabang: {{ $usage['branches.max']['used'] ?? 0 }}</div></div></div></div>
    <div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title">Aksi cepat</h3></div><div class="card-body"><a class="btn btn-primary" href="{{ route('pos.index') }}">Buka POS</a></div></div></div>
</div>
@endsection
