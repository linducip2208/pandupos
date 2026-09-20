@extends('layouts.tabler')
@section('title', 'Ecommerce')
@section('header', 'Ecommerce · Katalog & Pesanan Online')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Pesanan online</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Nomor</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($orders as $o)<tr><td><strong>{{ $o->number }}</strong><div class="small text-secondary">{{ $o->email }} · {{ $o->shipping_method }}</div></td><td class="text-end">Rp {{ number_format($o->total,0,',','.') }}</td><td><span class="badge">{{ $o->status }}</span></td><td class="text-nowrap">
@if($o->status==='pending')<form method="POST" action="{{ route('ecommerce.orders.transition',$o) }}" class="d-inline">@csrf<input type="hidden" name="action" value="pay"><input type="hidden" name="amount" value="{{ $o->total }}"><input type="hidden" name="method" value="transfer"><button class="btn btn-sm btn-success">Bayar</button></form>
<form method="POST" action="{{ route('ecommerce.orders.transition',$o) }}" class="d-inline">@csrf<input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-outline-danger">Batal</button></form>@endif
@if($o->status==='paid')<form method="POST" action="{{ route('ecommerce.orders.transition',$o) }}" class="d-flex gap-1">@csrf<input type="hidden" name="action" value="ship"><input name="tracking_number" class="form-control form-control-sm" placeholder="Resi" style="width:120px"><button class="btn btn-sm btn-primary">Kirim</button></form>@endif
@if($o->status==='shipped')<form method="POST" action="{{ route('ecommerce.orders.transition',$o) }}" class="d-inline">@csrf<input type="hidden" name="action" value="deliver"><button class="btn btn-sm btn-success">Selesai</button></form>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada pesanan.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Katalog online</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Produk</th><th>Online</th><th>Aksi</th></tr></thead><tbody>
@foreach($products as $p)<tr><td>{{ $p->name }}</td><td>{{ $p->is_online ? 'Ya' : '—' }}</td><td>
<form method="POST" action="{{ route('ecommerce.products.publish',$p) }}" class="d-inline">@csrf<input type="hidden" name="online" value="{{ $p->is_online ? 0 : 1 }}"><button class="btn btn-sm btn-outline-primary">{{ $p->is_online ? 'Tarik' : 'Tampilkan' }}</button></form>
</td></tr>@endforeach
</tbody></table></div></div></div>
</div>
@endsection
