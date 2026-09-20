@extends('layouts.tabler')
@section('title', 'Aset')
@section('header', 'Aset · Registrasi, Penyusutan & Pelepasan')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8"><div class="card"><div class="card-header"><h3 class="card-title">Daftar aset</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Kode</th><th>Nilai buku</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($assets as $a)<tr><td><strong>{{ $a->code }}</strong><div class="small text-secondary">{{ $a->name }} · {{ $a->custodian?->name }}</div></td><td class="text-end">Rp {{ number_format($books[$a->id],0,',','.') }}@if($a->status==='disposed')<div class="small">Lepas Rp {{ number_format($a->disposal_proceeds,0,',','.') }} (selisih Rp {{ number_format($a->disposal_gain_loss,0,',','.') }})</div>@endif</td><td><span class="badge">{{ $a->status }}</span></td><td class="text-nowrap">
<a class="btn btn-sm btn-outline-secondary" href="{{ route('asset.schedule',$a) }}">Susut</a>
@if($a->status!=='disposed')<details class="d-inline"><summary class="btn btn-sm btn-outline-primary">Pindah</summary><form method="POST" action="{{ route('asset.transfer',$a) }}" class="mt-1">@csrf<select name="to_custodian_id" class="form-select form-select-sm mb-1"><option value="">Tanpa penanggung jawab</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select><input name="to_location" class="form-control form-control-sm mb-1" placeholder="Lokasi"><button class="btn btn-sm btn-primary w-100">Pindah</button></form></details>
<details class="d-inline"><summary class="btn btn-sm btn-outline-warning">Rawat</summary><form method="POST" action="{{ route('asset.maintain',$a) }}" class="mt-1">@csrf<input name="cost" type="number" min="0" step="0.01" value="0" class="form-control form-control-sm mb-1" placeholder="Biaya"><button class="btn btn-sm btn-warning w-100">Catat</button></form></details>
@if($a->status==='maintenance')<form method="POST" action="{{ route('asset.return',$a) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success">Kembali</button></form>@endif
<details class="d-inline"><summary class="btn btn-sm btn-outline-danger">Lepas</summary><form method="POST" action="{{ route('asset.dispose',$a) }}" class="mt-1">@csrf<input name="proceeds" type="number" min="0" step="0.01" class="form-control form-control-sm mb-1" placeholder="Hasil penjualan" required><button class="btn btn-sm btn-danger w-100" onclick="return confirm('Lepaskan aset ini?')">Lepas</button></form></details>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada aset.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Daftarkan aset</h3></div><div class="card-body"><form method="POST" action="{{ route('asset.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="160"></div>
<div class="col-6"><label class="form-label">Biaya perolehan</label><input name="purchase_cost" type="number" min="0.01" step="0.01" class="form-control" required></div>
<div class="col-6"><label class="form-label">Nilai sisa</label><input name="salvage_value" type="number" min="0" step="0.01" value="0" class="form-control"></div>
<div class="col-6"><label class="form-label">Umur (bulan)</label><input name="useful_life_months" type="number" min="1" step="1" value="12" class="form-control" required></div>
<div class="col-6"><label class="form-label">Metode</label><select name="depreciation_method" class="form-select"><option value="straight_line">Garis lurus</option><option value="declining_balance">Saldo menurun</option></select></div>
<div class="col-12"><button class="btn btn-primary w-100">Daftarkan</button></div></form></div></div></div>
</div>
@endsection
