@extends('layouts.tabler')
@section('title', 'Manufaktur · BOM')
@section('header', 'Manufaktur · Bill of Materials')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Daftar BOM (revisi aktif menonaktifkan versi lama)</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Produk jadi</th><th>Ver</th><th>Komponen</th><th>Status</th></tr></thead><tbody>
@forelse($boms as $b)<tr><td><strong>{{ $b->finishedVariant?->sku }}</strong><div class="small text-secondary">{{ $b->finishedVariant?->product?->name }}</div></td><td>v{{ $b->version }}</td><td>@foreach($b->lines as $l)<div class="small">{{ $l->component?->sku }} × {{ $l->quantity }}{{ $l->scrap_rate > 0 ? ' (+'.($l->scrap_rate*100).'%)' : '' }}</div>@endforeach</td><td>@if($b->is_active)<span class="badge bg-success">Aktif</span>@else<span class="badge bg-secondary">Nonaktif</span>@endif</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada BOM. <a href="{{ route('mrp.orders') }}">Work order</a></td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">BOM baru</h3></div><div class="card-body"><form method="POST" action="{{ route('mrp.boms.store') }}" id="bom-form">@csrf
<div class="mb-2"><label class="form-label">Produk jadi (varian)</label><select name="finished_variant_id" class="form-select" required><option value="">Pilih varian</option>@foreach($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }} · {{ $v->product?->name }}</option>@endforeach</select></div>
<div id="bom-lines">
@for($i=0;$i<2;$i++)<div class="row g-1 mb-1 bom-line"><div class="col-7"><select name="lines[{{ $i }}][component_variant_id]" class="form-select" required><option value="">Pilih komponen</option>@foreach($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }}</option>@endforeach</select></div><div class="col-3"><input name="lines[{{ $i }}][quantity]" type="number" min="0.001" step="0.001" value="1" class="form-control" placeholder="Qty" required></div><div class="col-2"><button type="button" class="btn btn-outline-danger remove-line" aria-label="Hapus">×</button></div></div>@endfor
</div>
<button type="button" id="add-line" class="btn btn-sm btn-outline-primary mb-2">Tambah komponen</button>
<button class="btn btn-primary w-100" type="submit">Simpan BOM</button></form></div></div></div>
</div>
@push('scripts')<script>
(()=>{const box=document.getElementById('bom-lines');let i=box.querySelectorAll('.bom-line').length;document.getElementById('add-line').addEventListener('click',()=>{const row=document.createElement('div');row.className='row g-1 mb-1 bom-line';const opts=box.querySelector('select').innerHTML;row.innerHTML=`<div class="col-7"><select name="lines[${i}][component_variant_id]" class="form-select" required>${opts}</select></div><div class="col-3"><input name="lines[${i}][quantity]" type="number" min="0.001" step="0.001" value="1" class="form-control" required></div><div class="col-2"><button type="button" class="btn btn-outline-danger remove-line" aria-label="Hapus">×</button></div>`;box.appendChild(row);i++;});box.addEventListener('click',e=>{if(e.target.closest('.remove-line')&&box.querySelectorAll('.bom-line').length>1)e.target.closest('.bom-line').remove();});})();
</script>@endpush
@endsection
