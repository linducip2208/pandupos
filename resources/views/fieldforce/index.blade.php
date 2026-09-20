@extends('layouts.tabler')
@section('title', 'Field Force')
@section('header', 'Field Force · Tugas, Kunjungan & Bukti')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8"><div class="card"><div class="card-header"><h3 class="card-title">Tugas lapangan</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Tugas</th><th>Status</th><th>Kunjungan</th><th>Aksi</th></tr></thead><tbody>
@forelse($tasks as $t)<tr><td><strong>{{ $t->title }}</strong><div class="small text-secondary">{{ $t->assignee?->name }} · {{ $t->address }}</div></td><td><span class="badge">{{ $t->status }}</span></td><td class="small">@foreach($t->visits as $v)<div>{{ $v->check_in_at->format('d/m H:i') }}{{ $v->check_out_at ? ' → '.$v->check_out_at->format('H:i').' ('.$v->distance_m.' m)' : ' · berjalan' }}</div>@endforeach</td><td class="text-nowrap">
@if($t->status==='assigned')<form method="POST" action="{{ route('fieldforce.transition',$t) }}" class="d-inline">@csrf<input type="hidden" name="action" value="en_route"><button class="btn btn-sm btn-outline-primary">Berangkat</button></form>@endif
@if(in_array($t->status,['assigned','en_route'],true))<details class="d-inline"><summary class="btn btn-sm btn-primary">Check-in</summary><form method="POST" action="{{ route('fieldforce.checkin',$t) }}" class="mt-1">@csrf<input name="lat" class="form-control form-control-sm mb-1" placeholder="Lat (-90..90)" required><input name="lng" class="form-control form-control-sm mb-1" placeholder="Lng (-180..180)" required><button class="btn btn-sm btn-primary w-100">Catat</button></form></details>@endif
@foreach($t->visits->whereNull('check_out_at') as $v)<details class="d-inline"><summary class="btn btn-sm btn-outline-success">Check-out</summary><form method="POST" action="{{ route('fieldforce.checkout',$v) }}" enctype="multipart/form-data" class="mt-1">@csrf<input name="lat" class="form-control form-control-sm mb-1" placeholder="Lat" required><input name="lng" class="form-control form-control-sm mb-1" placeholder="Lng" required><input name="photo" type="file" accept="image/*" class="form-control form-control-sm mb-1"><button class="btn btn-sm btn-success w-100">Selesai kunjungan</button></form></details>@endforeach
@if($t->status==='checked_in')<form method="POST" action="{{ route('fieldforce.transition',$t) }}" class="d-inline">@csrf<input type="hidden" name="action" value="complete"><button class="btn btn-sm btn-success">Tugas selesai</button></form>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada tugas.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Tugas baru</h3></div><div class="card-body"><form method="POST" action="{{ route('fieldforce.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Judul</label><input name="title" class="form-control" required maxlength="200"></div>
<div class="col-12"><label class="form-label">Petugas</label><select name="assignee_id" class="form-select"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Alamat</label><input name="address" class="form-control" maxlength="255"></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat tugas</button></div></form></div></div></div>
</div>
@endsection
