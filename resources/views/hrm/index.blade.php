@extends('layouts.tabler')
@section('title', 'HRM')
@section('header', 'HRM · Karyawan, Kehadiran & Cuti')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Karyawan (jatah cuti tahun berjalan)</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Kode</th><th>Cuti</th><th>Hari ini</th><th>Aksi</th></tr></thead><tbody>
@forelse($employees as $e)<tr><td><strong>{{ $e->code }}</strong><div class="small text-secondary">{{ $e->name }} · {{ $e->department?->name }} · <span class="badge">{{ $e->status }}</span></div></td><td class="small">Tahunan: {{ $balances[$e->id]['annual'] }} · Sakit: {{ $balances[$e->id]['sick'] }}</td><td class="small">@php($t = $today->firstWhere('employee_id', $e->id)){{ $t ? ($t->status.($t->check_in ? ' '.$t->check_in->format('H:i') : '')) : '—' }}</td><td class="text-nowrap">
<form method="POST" action="{{ route('hrm.employees.checkin',$e) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">In</button></form>
<form method="POST" action="{{ route('hrm.employees.checkout',$e) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">Out</button></form>
<details class="d-inline"><summary class="btn btn-sm btn-outline-warning">Cuti</summary><form method="POST" action="{{ route('hrm.employees.leave',$e) }}" class="mt-1">@csrf<select name="type" class="form-select form-select-sm mb-1"><option value="annual">Tahunan</option><option value="sick">Sakit</option><option value="unpaid">Tanpa gaji</option></select><input name="starts_on" type="date" class="form-control form-control-sm mb-1" required><input name="ends_on" type="date" class="form-control form-control-sm mb-1"><button class="btn btn-sm btn-warning w-100">Ajukan</button></form></details>
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada karyawan.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Pengajuan cuti</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Karyawan</th><th>Jenis</th><th>Rentang</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($leaves as $l)<tr><td>{{ $l->employee?->name }}</td><td>{{ $l->type }}</td><td>{{ $l->starts_on->toDateString() }} s.d. {{ $l->ends_on->toDateString() }} ({{ $l->days }} hari)</td><td><span class="badge">{{ $l->status }}</span></td><td class="text-nowrap">
@if($l->status==='pending')<form method="POST" action="{{ route('hrm.leaves.decide',$l) }}" class="d-inline">@csrf<input type="hidden" name="decision" value="approved"><button class="btn btn-sm btn-success">Setuju</button></form>
<form method="POST" action="{{ route('hrm.leaves.decide',$l) }}" class="d-inline">@csrf<input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-outline-danger">Tolak</button></form>@endif
</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary">Belum ada pengajuan.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Daftarkan karyawan</h3></div><div class="card-body"><form method="POST" action="{{ route('hrm.employees.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="160"></div>
<div class="col-6"><label class="form-label">Departemen</label><select name="department_id" class="form-select"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Jabatan</label><input name="position" class="form-control" maxlength="128"></div>
<div class="col-12"><button class="btn btn-primary w-100">Daftarkan</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Hari libur</h3></div><div class="card-body"><form method="POST" action="{{ route('hrm.holidays.store') }}" class="d-flex gap-1 mb-2">@csrf<input name="holiday_on" type="date" class="form-control form-control-sm" required><input name="name" class="form-control form-control-sm" placeholder="Nama libur" required><button class="btn btn-sm btn-primary">+</button></form>
@foreach($holidays as $h)<div class="small">{{ $h->holiday_on->toDateString() }} · {{ $h->name }}</div>@endforeach</div></div></div>
</div>
@endsection
