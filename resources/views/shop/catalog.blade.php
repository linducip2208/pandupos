@extends('layouts.public')
@section('title', $tenant->name.' — Toko Online')
@section('description', 'Katalog online '.$tenant->name)
@section('content')
<section class="container py-5"><h1>{{ $tenant->name }}</h1><p class="text-secondary">Katalog online</p>
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="row g-3">
@forelse($items as $item)<div class="col-md-4"><div class="card h-100"><div class="card-body"><h3 class="card-title">{{ $item['name'] }}</h3>
@foreach($item['variants'] as $v)<div class="d-flex justify-content-between align-items-center border-top py-2"><div><strong>{{ $v['name'] }}</strong><div class="small text-secondary">Rp {{ number_format($v['price'],0,',','.') }}</div></div>
<form method="POST" action="{{ route('shop.cart.add',$tenant->slug) }}" class="d-flex gap-1">@csrf<input type="hidden" name="variant_id" value="{{ $v['id'] }}"><input name="email" type="email" class="form-control form-control-sm" placeholder="Email" required style="width:150px"><input name="quantity" type="number" min="0.001" step="0.001" value="1" class="form-control form-control-sm" style="width:70px" required><button class="btn btn-sm btn-primary">+ Keranjang</button></form></div>@endforeach
</div></div></div>
@empty<p class="text-secondary">Katalog masih kosong.</p>@endforelse
</div>
<div class="mt-4"><a class="btn btn-outline-primary" href="{{ route('shop.checkout.form',$tenant->slug) }}">Lanjut ke checkout</a> <a class="btn btn-link" href="{{ route('shop.track',$tenant->slug) }}">Lacak pesanan</a></div>
</section>
@endsection
