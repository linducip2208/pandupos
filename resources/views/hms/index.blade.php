@extends('layouts.tabler')
@section('title', 'HMS')
@section('header', 'HMS · Pasien, Kunjungan & Tagihan')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Jadwal kunjungan</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Jadwal</th><th>Pasien / Dokter</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($appointments as $a)<tr><td>{{ $a->scheduled_at->format('d/m H:i') }} ({{ $a->duration_minutes }} mnt)</td><td><strong>{{ $a->patient?->name }}</strong><div class="small text-secondary">{{ $a->doctor?->name }}</div></td><td><span class="badge">{{ $a->status }}</span></td><td class="text-nowrap">
@if($a->status==='scheduled')<form method="POST" action="{{ route('hms.appointments.transition',$a) }}" class="d-inline">@csrf<input type="hidden" name="to" value="checked_in"><button class="btn btn-sm btn-primary">Check-in</button></form>
<form method="POST" action="{{ route('hms.appointments.transition',$a) }}" class="d-inline">@csrf<input type="hidden" name="to" value="cancelled"><button class="btn btn-sm btn-outline-danger">Batal</button></form>@endif
@if($a->status==='checked_in')<form method="POST" action="{{ route('hms.appointments.transition',$a) }}" class="d-inline">@csrf<input type="hidden" name="to" value="completed"><button class="btn btn-sm btn-success">Selesai</button></form>
<details class="d-inline"><summary class="btn btn-sm btn-outline-secondary">Rekam</summary><form method="POST" action="{{ route('hms.appointments.record',$a) }}" class="mt-1">@csrf<input name="diagnosis" class="form-control form-control-sm mb-1" placeholder="Diagnosis" required><input name="prescription" class="form-control form-control-sm mb-1" placeholder="Resep"><button class="btn btn-sm btn-secondary w-100">Simpan</button></form></details>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada jadwal.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tagihan (apotek terpotong saat lunas)</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Nomor</th><th class="text-end">Total</th><th class="text-end">Saldo</th><th>Aksi</th></tr></thead><tbody>
@forelse($invoices as $i)<tr><td><strong>{{ $i->number }}</strong></td><td class="text-end">{{ number_format($i->total,0,',','.') }}</td><td class="text-end fw-bold">{{ number_format($i->balance,0,',','.') }}</td><td>
@if($i->balance > 0)<details><summary class="btn btn-sm btn-outline-success">Bayar</summary><form method="POST" action="{{ route('hms.invoices.pay',$i) }}" class="mt-1">@csrf<input name="amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm mb-1" placeholder="Nominal" required><button class="btn btn-sm btn-success w-100">Catat</button></form></details>@else<span class="badge">lunas</span>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Belum ada tagihan.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Pasien baru</h3></div><div class="card-body"><form method="POST" action="{{ route('hms.patients.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="160"></div>
<div class="col-6"><label class="form-label">Telepon</label><input name="phone" class="form-control" maxlength="64"></div>
<div class="col-6"><label class="form-label">Tanggal lahir</label><input name="birth_date" type="date" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary w-100">Daftarkan</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Jadwalkan kunjungan</h3></div><div class="card-body"><form method="POST" action="{{ route('hms.appointments.schedule') }}" class="row g-2">@csrf
<div class="col-6"><label class="form-label">Pasien</label><select name="patient_id" class="form-select" required>@foreach($patients as $p)<option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Dokter</label><select name="doctor_id" class="form-select" required>@foreach($doctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Waktu</label><input name="scheduled_at" type="datetime-local" class="form-control" required></div>
<div class="col-6"><label class="form-label">Durasi (mnt)</label><input name="duration_minutes" type="number" min="5" value="30" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary w-100">Jadwalkan</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tagihan baru</h3></div><div class="card-body"><form method="POST" action="{{ route('hms.invoices.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Pasien</label><select name="patient_id" class="form-select" required>@foreach($patients as $p)<option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Jasa dokter</label><input name="consultation_fee" type="number" min="0" step="0.01" value="0" class="form-control" required></div>
<div class="col-6"><label class="form-label">Diskon</label><input name="discount" type="number" min="0" step="0.01" value="0" class="form-control"></div>
<div class="col-12"><label class="form-label">Obat (varian ID)</label><input name="pharmacy[0][variant_id]" type="number" class="form-control" placeholder="ID varian (opsional)"></div>
<div class="col-12"><label class="form-label">Qty obat</label><input name="pharmacy[0][quantity]" type="number" min="0" step="0.001" class="form-control" placeholder="Qty"></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat tagihan</button></div></form></div></div></div>
</div>
@endsection
