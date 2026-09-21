@extends('layouts.tabler')
@section('title', 'Modifier')
@section('header', 'Restaurant · Grup & Opsi Modifier')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Grup modifier</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Grup</th><th>Pilih</th><th>Opsi</th><th>Aksi</th></tr></thead><tbody>
@forelse($groups as $g)<tr><td><strong>{{ $g->name }}</strong></td><td>{{ $g->min_select }}–{{ $g->max_select }}</td><td>@foreach($g->options as $o)<div class="small">{{ $o->name }} (+Rp {{ number_format($o->price_delta,0,',','.') }})</div>@endforeach</td><td>
<details><summary class="btn btn-sm btn-outline-primary">+ Opsi</summary><form method="POST" action="{{ route('restaurant.modifiers.store',$g) }}" class="mt-1 row g-1">@csrf<div class="col-6"><input name="name" class="form-control form-control-sm" placeholder="Nama" required></div><div class="col-6"><input name="price_delta" type="number" step="0.01" value="0" class="form-control form-control-sm" required></div><div class="col-12"><button class="btn btn-sm btn-primary w-100">Simpan</button></div></form></details>
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Belum ada grup.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Grup baru</h3></div><div class="card-body"><form method="POST" action="{{ route('restaurant.groups.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
<div class="col-6"><label class="form-label">Min</label><input name="min_select" type="number" min="0" value="0" class="form-control"></div>
<div class="col-6"><label class="form-label">Maks</label><input name="max_select" type="number" min="1" value="1" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat grup</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tautkan ke produk</h3></div><div class="card-body"><form method="POST" action="{{ route('restaurant.products.link') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Produk</label><select name="product_id" class="form-select" required>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Grup</label><select name="group_id" class="form-select" required>@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select></div>
<div class="col-12"><button class="btn btn-primary w-100">Tautkan</button></div></form></div></div></div>
</div>
@endsection
