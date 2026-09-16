@extends('portal.layout')
@section('title', 'Detail Pesanan')
@section('content')
<div class="portal-heading"><div><span class="eyebrow">DETAIL PESANAN</span><h1>{{ $invoice->invoice_no ?: '#'.$invoice->id }}</h1></div><a class="portal-link-button" href="{{ route('portal.orders.index') }}">Kembali</a></div>
@include('portal.partials.invoice-detail', ['showPayments' => true])
@endsection
