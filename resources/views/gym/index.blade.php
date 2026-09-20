@extends('layouts.tabler')
@section('title', 'Gym')
@section('header', 'Gym · Member, Langganan & Kehadiran')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8"><div class="card"><div class="card-header"><h3 class="card-title">Langganan</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Member</th><th>Paket</th><th>Berlaku</th><th>Saldo</th><th>Aksi</th></tr></thead><tbody>
@forelse($memberships as $m)<tr><td><strong>{{ $m->member?->name }}</strong><div class="small text-secondary">{{ $m->member?->code }} · {{ $m->visits_used }}{{ $m->visits_limit ? '/'.$m->visits_limit : '' }} kunjungan</div></td><td>{{ $m->package?->name }}</td><td class="small">{{ $m->starts_on->toDateString() }} s.d. {{ $m->ends_on->toDateString() }}<div><span class="badge">{{ $m->status }}</span></div></td><td class="text-end">Rp {{ number_format($m->balance,0,',','.') }}</td><td class="text-nowrap">
@if($m->status==='active')<form method="POST" action="{{ route('gym.checkin',$m) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Check-in</button></form>
<details class="d-inline"><summary class="btn btn-sm btn-outline-success">Bayar</summary><form method="POST" action="{{ route('gym.pay',$m) }}" class="mt-1">@csrf<input name="amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm mb-1" placeholder="Nominal" required><button class="btn btn-sm btn-success w-100">Catat</button></form></details>
<form method="POST" action="{{ route('gym.cancel',$m) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-danger">Batal</button></form>@endif
</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada langganan.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Member baru</h3></div><div class="card-body"><form method="POST" action="{{ route('gym.members.store') }}" class="d-flex gap-1">@csrf<input name="name" class="form-control" placeholder="Nama member" required><button class="btn btn-primary">+</button></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Langganan baru</h3></div><div class="card-body"><form method="POST" action="{{ route('gym.subscribe') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Member</label><select name="member_id" class="form-select" required>@foreach($members as $m)<option value="{{ $m->id }}">{{ $m->code }} · {{ $m->name }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Paket</label><select name="package_id" class="form-select" required>@foreach($packages as $p)<option value="{{ $p->id }}">{{ $p->name }} · Rp {{ number_format($p->price,0,',','.') }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Mulai</label><input name="starts_on" type="date" value="{{ now()->toDateString() }}" class="form-control" required></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat langganan</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Paket baru</h3></div><div class="card-body"><form method="POST" action="{{ route('gym.packages.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
<div class="col-4"><label class="form-label">Hari</label><input name="duration_days" type="number" min="1" value="30" class="form-control" required></div>
<div class="col-4"><label class="form-label">Harga</label><input name="price" type="number" min="0" step="0.01" value="0" class="form-control" required></div>
<div class="col-4"><label class="form-label">Kunjungan</label><input name="visits_limit" type="number" min="1" class="form-control" placeholder="∞"></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat paket</button></div></form></div></div></div>
</div>
@endsection
