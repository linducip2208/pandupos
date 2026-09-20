@extends('layouts.tabler')
@section('title', 'Reparasi')
@section('header', 'Reparasi · Order Servis & Parts')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8"><div class="card"><div class="card-header"><h3 class="card-title">Order servis</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Nomor</th><th>Unit / Keluhan</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($orders as $o)<tr><td><strong>{{ $o->number }}</strong><div class="small text-secondary">{{ $o->contact?->name }}@if($o->warranty) · <span class="badge bg-info">garansi</span>@endif</div></td><td>{{ $o->device_brand }} {{ $o->device_model }}<div class="small text-secondary">{{ $o->complaint }}</div>@if($o->diagnosis)<div class="small">Dx: {{ $o->diagnosis }}</div>@endif@foreach($o->parts as $p)<div class="small">+ {{ $p->variant?->sku }} × {{ $p->quantity }}</div>@endforeach</td><td class="text-end">Rp {{ number_format($o->total,0,',','.') }}<div class="small text-secondary">Dibayar Rp {{ number_format($o->paid,0,',','.') }}</div></td><td><span class="badge">{{ $o->status }}</span></td><td class="text-nowrap">
@if($o->status==='received')<details><summary class="btn btn-sm btn-outline-primary">Diagnosis</summary><form method="POST" action="{{ route('repair.orders.diagnose',$o) }}" class="mt-1">@csrf<input name="diagnosis" class="form-control form-control-sm mb-1" placeholder="Hasil diagnosis" required><input name="labor_cost" type="number" min="0" step="0.01" value="0" class="form-control form-control-sm mb-1" placeholder="Ongkos jasa"><button class="btn btn-sm btn-primary w-100">Simpan</button></form></details>@endif
@if(in_array($o->status,['diagnosed','waiting_parts'],true))<form method="POST" action="{{ route('repair.orders.transition',$o) }}" class="d-inline">@csrf<input type="hidden" name="action" value="start"><button class="btn btn-sm btn-primary">Mulai</button></form>@endif
@if(in_array($o->status,['diagnosed','in_progress'],true))<form method="POST" action="{{ route('repair.orders.transition',$o) }}" class="d-inline">@csrf<input type="hidden" name="action" value="waiting"><button class="btn btn-sm btn-outline-warning">Tunggu part</button></form>@endif
@if(in_array($o->status,['in_progress','diagnosed'],true))<form method="POST" action="{{ route('repair.orders.transition',$o) }}" class="d-inline">@csrf<input type="hidden" name="action" value="ready"><button class="btn btn-sm btn-success">Siap</button></form>@endif
@if($o->status==='ready')<details><summary class="btn btn-sm btn-outline-success">Bayar</summary><form method="POST" action="{{ route('repair.orders.pay',$o) }}" class="mt-1">@csrf<input name="amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm mb-1" placeholder="Nominal" required><input name="method" class="form-control form-control-sm mb-1" value="cash" required><button class="btn btn-sm btn-success w-100">Catat</button></form></details>
<form method="POST" action="{{ route('repair.orders.deliver',$o) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success mt-1" onclick="return confirm('Serahkan unit? Stok part terpotong sekali.')">Serahkan</button></form>@endif
@if(in_array($o->status,['diagnosed','in_progress'],true))<details><summary class="btn btn-sm btn-outline-secondary">+ Part</summary><form method="POST" action="{{ route('repair.orders.parts',$o) }}" class="mt-1">@csrf<select name="product_variant_id" class="form-select form-select-sm mb-1" required><option value="">Pilih part</option>@foreach($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }}</option>@endforeach</select><input name="quantity" type="number" min="0.001" step="0.001" value="1" class="form-control form-control-sm mb-1" required><button class="btn btn-sm btn-secondary w-100">Tambah</button></form></details>@endif
</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada order servis.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Terima unit</h3></div><div class="card-body"><form method="POST" action="{{ route('repair.orders.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Pelanggan</label><select name="contact_id" class="form-select"><option value="">Umum</option>@foreach($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Merek</label><input name="device_brand" class="form-control" maxlength="64"></div>
<div class="col-6"><label class="form-label">Model</label><input name="device_model" class="form-control" maxlength="128"></div>
<div class="col-12"><label class="form-label">Keluhan</label><textarea name="complaint" rows="2" class="form-control" required></textarea></div>
<div class="col-12"><label class="form-label">Gudang part</label><select name="warehouse_id" class="form-select" required>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-check"><input type="checkbox" name="warranty" value="1" class="form-check-input"><span class="form-check-label">Masih garansi (jasa + part gratis)</span></label></div>
<div class="col-12"><button class="btn btn-primary w-100">Terima unit</button></div></form></div></div></div>
</div>
@endsection
