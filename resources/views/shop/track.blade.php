@extends('layouts.public')
@section('title', 'Lacak Pesanan — '.$tenant->name)
@section('content')
<section class="container py-5"><h1>Lacak pesanan</h1>
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
<div class="card mb-3"><div class="card-body"><form method="GET" action="{{ route('shop.track',$tenant->slug) }}" class="row g-2"><div class="col-md-5"><input name="number" class="form-control" placeholder="Nomor pesanan" value="{{ request('number') }}"></div><div class="col-md-5"><input name="email" type="email" class="form-control" placeholder="Email" value="{{ request('email') }}"></div><div class="col-md-2"><button class="btn btn-primary w-100">Lacak</button></div></form></div></div>
@if($order)<div class="card"><div class="card-body"><h3>{{ $order->number }} <span class="badge bg-primary">{{ $order->status }}</span></h3>
<p>Total Rp {{ number_format($order->total,0,',','.') }} · Dibayar Rp {{ number_format($order->paid,0,',','.') }}@if($order->tracking_number) · Resi {{ $order->tracking_number }}@endif</p>
<p class="text-secondary">{{ $order->recipient }} · {{ $order->address }}</p></div></div>@endif
</section>
@endsection
