@extends('layouts.tabler')
@section('title', 'Manufaktur · Work Order')
@section('header', 'Manufaktur · Work Order')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title">Work order</h3><div class="card-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('mrp.boms.index') }}">Kelola BOM</a></div></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Nomor</th><th>Produk</th><th>Rencana</th><th>Hasil</th><th>Biaya</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($orders as $o)<tr><td><strong>{{ $o->number }}</strong><div class="small text-secondary">{{ $o->warehouse?->name }}</div></td><td>{{ $o->finishedVariant?->sku }}</td><td>{{ $o->quantity_planned }}</td><td>{{ $o->quantity_produced }} baik / {{ $o->quantity_scrapped }} scrap</td><td>Rp {{ number_format($o->material_cost,0,',','.') }}@if($o->unit_cost)<div class="small text-secondary">Rp {{ number_format($o->unit_cost,0,',','.') }}/unit</div>@endif</td><td><span class="badge">{{ $o->status }}</span>@if(isset($shortages[$o->id]) && count($shortages[$o->id])>0)<div class="small text-danger mt-1">Kurang: @foreach($shortages[$o->id] as $s){{ $s['sku'] }} ({{ $s['available'] }}/{{ $s['required'] }}) @endforeach</div>@endif</td><td class="text-nowrap">
@if($o->status==='draft')<form method="POST" action="{{ route('mrp.orders.release',$o) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Rilis</button></form>@endif
@if($o->status==='released')<form method="POST" action="{{ route('mrp.orders.start',$o) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Mulai</button></form>@endif
@if($o->status==='in_progress')<form method="POST" action="{{ route('mrp.orders.produce',$o) }}" class="d-flex gap-1 mt-1">@csrf<input name="quantity_good" type="number" min="0" step="0.001" value="0" class="form-control form-control-sm" style="width:90px" placeholder="Baik"><input name="quantity_scrap" type="number" min="0" step="0.001" value="0" class="form-control form-control-sm" style="width:90px" placeholder="Scrap"><button class="btn btn-sm btn-success">Catat</button></form>
<form method="POST" action="{{ route('mrp.orders.finish',$o) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success mt-1">Selesai</button></form>@endif
@if(in_array($o->status,['draft','released'],true))<form method="POST" action="{{ route('mrp.orders.cancel',$o) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger mt-1">Batal</button></form>@endif
</td></tr>
@empty<tr><td colspan="7" class="text-center text-secondary py-4">Belum ada work order.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Work order baru</h3></div><div class="card-body"><form method="POST" action="{{ route('mrp.orders.store') }}" class="row g-2">@csrf
<div class="col-md-5"><label class="form-label">BOM aktif</label><select name="bom_id" class="form-select" required><option value="">Pilih BOM</option>@foreach($boms as $b)<option value="{{ $b->id }}">{{ $b->finishedVariant?->sku }} v{{ $b->version }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
<div class="col-md-2"><label class="form-label">Qty rencana</label><input name="quantity_planned" type="number" min="0.001" step="0.001" class="form-control" required></div>
<div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Buat WO</button></div></form></div></div></div>
</div>
@endsection
