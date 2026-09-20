@extends('layouts.tabler')
@section('title', 'CRM')
@section('header', 'CRM · Prospek, Pipeline & Tindak Lanjut')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards mb-3">
@foreach($pipeline as $s)<div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="text-secondary">{{ $s['stage'] }}</div><div class="h3 mb-0">{{ $s['count'] }} prospek</div><div class="text-secondary">Rp {{ number_format($s['value'],0,',','.') }}</div></div></div></div>@endforeach
</div>
@if(count($overdue) > 0)<div class="alert alert-warning"><strong>{{ count($overdue) }} tindak lanjut telat:</strong><ul class="mb-0">@foreach($overdue as $a)<li>{{ $a->subject }} ({{ $a->scheduled_at?->format('d/m/Y H:i') }})</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Prospek</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Nama</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($leads as $l)<tr><td><strong>{{ $l->name }}</strong><div class="small text-secondary">{{ $l->company }} · {{ $l->email }}</div></td><td><span class="badge">{{ $l->status }}</span></td><td class="text-nowrap">
@if($l->status==='new')<form method="POST" action="{{ route('crm.leads.transition',$l) }}" class="d-inline">@csrf<input type="hidden" name="to" value="contacted"><button class="btn btn-sm btn-outline-primary">Hubungi</button></form>@endif
@if($l->status==='contacted')<form method="POST" action="{{ route('crm.leads.transition',$l) }}" class="d-inline">@csrf<input type="hidden" name="to" value="qualified"><button class="btn btn-sm btn-outline-primary">Kualifikasi</button></form>@endif
@if(in_array($l->status,['contacted','qualified'],true))<form method="POST" action="{{ route('crm.leads.convert',$l) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Konversi</button></form>@endif
@if(!in_array($l->status,['converted','lost'],true))<form method="POST" action="{{ route('crm.leads.transition',$l) }}" class="d-inline">@csrf<input type="hidden" name="to" value="lost"><button class="btn btn-sm btn-outline-danger">Lost</button></form>@endif
</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary py-4">Belum ada prospek.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tambah prospek</h3></div><div class="card-body"><form method="POST" action="{{ route('crm.leads.store') }}" class="row g-2">@csrf
<div class="col-6"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="128"></div>
<div class="col-6"><label class="form-label">Perusahaan</label><input name="company" class="form-control" maxlength="128"></div>
<div class="col-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control" maxlength="128"></div>
<div class="col-6"><label class="form-label">Telepon</label><input name="phone" class="form-control" maxlength="64"></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan prospek</button></div></form></div></div></div>
<div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Opportunity</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Judul</th><th>Nilai</th><th>Tahap</th><th>Aksi</th></tr></thead><tbody>
@forelse($opportunities as $o)<tr><td><strong>{{ $o->title }}</strong><div class="small text-secondary">{{ $o->lead?->name }}{{ $o->contact?->name }}</div></td><td class="text-end">Rp {{ number_format($o->value,0,',','.') }}</td><td><span class="badge">{{ $o->stage }}</span></td><td class="text-nowrap">
@if($o->stage==='prospect')<form method="POST" action="{{ route('crm.opportunities.advance',$o) }}" class="d-inline">@csrf<input type="hidden" name="to" value="negotiation"><button class="btn btn-sm btn-outline-primary">Negosiasi</button></form>@endif
@if($o->stage==='negotiation')<form method="POST" action="{{ route('crm.opportunities.advance',$o) }}" class="d-inline">@csrf<input type="hidden" name="to" value="won"><button class="btn btn-sm btn-success">Menang</button></form>@endif
@if(in_array($o->stage,['prospect','negotiation'],true))<form method="POST" action="{{ route('crm.opportunities.advance',$o) }}" class="d-inline">@csrf<input type="hidden" name="to" value="lost"><button class="btn btn-sm btn-outline-danger">Kalah</button></form>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada opportunity.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tambah opportunity</h3></div><div class="card-body"><form method="POST" action="{{ route('crm.opportunities.store') }}" class="row g-2">@csrf
<div class="col-8"><label class="form-label">Judul</label><input name="title" class="form-control" required maxlength="160"></div>
<div class="col-4"><label class="form-label">Nilai (Rp)</label><input name="value" type="number" min="0" step="0.01" value="0" class="form-control" required></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan opportunity</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Catat aktivitas</h3></div><div class="card-body"><form method="POST" action="{{ route('crm.activities.store') }}" class="row g-2">@csrf
<div class="col-4"><label class="form-label">Lead ID</label><input name="lead_id" type="number" class="form-control"></div>
<div class="col-4"><label class="form-label">Tipe</label><select name="type" class="form-select">@foreach(['call'=>'Telepon','meeting'=>'Rapat','email'=>'Email','note'=>'Catatan','follow-up'=>'Tindak lanjut'] as $v=>$l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
<div class="col-4"><label class="form-label">Jadwal</label><input name="scheduled_at" type="datetime-local" class="form-control"></div>
<div class="col-12"><label class="form-label">Subjek</label><input name="subject" class="form-control" required maxlength="160"></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan aktivitas</button></div></form></div></div></div>
</div>
@endsection
