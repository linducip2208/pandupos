@extends('layouts.tabler')
@section('title', 'WooCommerce')
@section('header', 'WooCommerce · Konektor & Log Sinkronisasi')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Koneksi toko</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Nama</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($connections as $c)<tr><td><strong>{{ $c->name }}</strong><div class="small text-secondary">{{ $c->store_url }} · {{ $c->links_count }} tautan · {{ $c->logs_count }} log</div>@if($c->last_error)<div class="small text-danger">{{ $c->last_error }}</div>@endif</td><td><span class="badge">{{ $c->status }}</span></td><td class="text-nowrap">
@foreach(['products'=>'Produk','inventory'=>'Stok','orders'=>'Order','customers'=>'Pelanggan'] as $job=>$label)<form method="POST" action="{{ route('woo.sync',$c) }}" class="d-inline">@csrf<input type="hidden" name="job" value="{{ $job }}"><button class="btn btn-sm btn-outline-primary" onclick="return confirm('Jalankan sinkronisasi {{ $label }} ke toko live?')">{{ $label }}</button></form>@endforeach
</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary">Belum ada koneksi.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tambah koneksi</h3></div><div class="card-body"><form method="POST" action="{{ route('woo.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="128"></div>
<div class="col-12"><label class="form-label">Store URL</label><input name="store_url" type="url" class="form-control" placeholder="https://toko.example" required></div>
<div class="col-6"><label class="form-label">Consumer key</label><input name="consumer_key" type="password" class="form-control" required autocomplete="off"></div>
<div class="col-6"><label class="form-label">Consumer secret</label><input name="consumer_secret" type="password" class="form-control" required autocomplete="off"></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan (terenkripsi)</button></div></form></div></div></div>
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Log sinkronisasi (100 terbaru)</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Waktu</th><th>Arah/Entitas</th><th>Hasil</th></tr></thead><tbody>
@forelse($logs as $l)<tr><td class="small">{{ $l->created_at->toDateTimeString() }}</td><td>{{ $l->direction }} {{ $l->entity }}<div class="small text-secondary">ext {{ $l->external_id }} · {{ $l->local_reference }}</div></td><td><span class="badge">{{ $l->status }}</span>@if($l->message)<div class="small text-secondary">{{ $l->message }}</div>@endif</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary">Belum ada log.</td></tr>@endforelse
</tbody></table></div></div></div>
</div>
@endsection
