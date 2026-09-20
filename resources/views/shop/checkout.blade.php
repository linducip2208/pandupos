@extends('layouts.public')
@section('title', 'Checkout — '.$tenant->name)
@section('content')
<section class="container py-5"><h1>Checkout</h1>
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@if(count($cart['lines']) === 0)<p class="text-secondary">Keranjang kosong untuk email ini. Kembali ke <a href="{{ route('shop.catalog',$tenant->slug) }}">katalog</a>.</p>@else
<div class="card mb-3"><div class="card-body"><table class="table"><thead><tr><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Harga</th></tr></thead><tbody>
@foreach($cart['lines'] as $l)<tr><td>{{ $l['sku'] }}</td><td class="text-end">{{ $l['quantity'] }}</td><td class="text-end">Rp {{ number_format($l['line_total'],0,',','.') }}</td></tr>@endforeach
</tbody></table><p class="text-end">Subtotal Rp {{ number_format($cart['total'],0,',','.') }} + ongkir</p></div></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('shop.checkout',$tenant->slug) }}" class="row g-2">@csrf
<div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="{{ $email }}" required></div>
<div class="col-md-6"><label class="form-label">Penerima</label><input name="recipient" class="form-control" required></div>
<div class="col-md-6"><label class="form-label">Telepon</label><input name="phone" class="form-control" required></div>
<div class="col-md-6"><label class="form-label">Kota</label><input name="city" class="form-control"></div>
<div class="col-12"><label class="form-label">Alamat</label><textarea name="address" rows="2" class="form-control" required></textarea></div>
<div class="col-md-6"><label class="form-label">Ekspedisi</label><select name="shipping_method" class="form-select"><option value="regular">Reguler (Rp 10.000)</option><option value="express">Express (Rp 25.000)</option><option value="sameday">Same day (Rp 50.000)</option></select></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat pesanan</button></div></form></div></div>
@endif
</section>
@endsection
