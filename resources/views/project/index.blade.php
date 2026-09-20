@extends('layouts.tabler')
@section('title', 'Proyek')
@section('header', 'Proyek · Tugas, Timesheet & Profitabilitas')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8">
@forelse($projects as $p)<div class="card mb-3"><div class="card-header"><h3 class="card-title">{{ $p->name }} <span class="badge">{{ $p->status }}</span></h3><div class="card-actions"><span class="text-secondary small">Anggaran Rp {{ number_format($p->budget,0,',','.') }} · Sisa Rp {{ number_format($profit[$p->id]['remaining'],0,',','.') }}</span></div></div>
<div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Tugas</th><th>Assignee</th><th>Est</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($p->tasks as $t)<tr><td>{{ $t->title }}</td><td>{{ $t->assignee?->name ?? '—' }}</td><td>{{ $t->estimate_hours }}j</td><td><span class="badge">{{ $t->status }}</span></td><td class="text-nowrap">
@if($t->status==='todo')<form method="POST" action="{{ route('project.tasks.advance',$t) }}" class="d-inline">@csrf<input type="hidden" name="to" value="doing"><button class="btn btn-sm btn-outline-primary">Kerjakan</button></form>@endif
@if($t->status==='doing')<form method="POST" action="{{ route('project.tasks.advance',$t) }}" class="d-inline">@csrf<input type="hidden" name="to" value="review"><button class="btn btn-sm btn-outline-primary">Review</button></form>@endif
@if($t->status==='review')<form method="POST" action="{{ route('project.tasks.advance',$t) }}" class="d-inline">@csrf<input type="hidden" name="to" value="done"><button class="btn btn-sm btn-success">Selesai</button></form>@endif
</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary">Belum ada tugas.</td></tr>@endforelse
</tbody></table></div>
<div class="card-body border-top"><div class="row g-2">
<div class="col-md-4"><form method="POST" action="{{ route('project.tasks.store',$p) }}" class="d-flex gap-1">@csrf<input name="title" class="form-control form-control-sm" placeholder="Tugas baru" required><button class="btn btn-sm btn-primary">+</button></form></div>
<div class="col-md-4"><form method="POST" action="{{ route('project.time.store',$p) }}" class="d-flex gap-1">@csrf<input name="hours" type="number" min="0.01" max="24" step="0.01" class="form-control form-control-sm" placeholder="Jam" required><button class="btn btn-sm btn-outline-primary">Catat jam</button></form></div>
<div class="col-md-4"><form method="POST" action="{{ route('project.expenses.store',$p) }}" class="d-flex gap-1">@csrf<input name="description" class="form-control form-control-sm" placeholder="Biaya" required><input name="amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm" style="width:110px" placeholder="Rp" required><button class="btn btn-sm btn-outline-primary">+</button></form></div>
</div><div class="mt-2">
@if($p->status==='planned')<form method="POST" action="{{ route('project.transition',$p) }}" class="d-inline">@csrf<input type="hidden" name="to" value="active"><button class="btn btn-sm btn-primary">Aktifkan</button></form>@endif
@if($p->status==='active')<form method="POST" action="{{ route('project.transition',$p) }}" class="d-inline">@csrf<input type="hidden" name="to" value="on_hold"><button class="btn btn-sm btn-outline-warning">Tahan</button></form>
<form method="POST" action="{{ route('project.transition',$p) }}" class="d-inline">@csrf<input type="hidden" name="to" value="completed"><button class="btn btn-sm btn-success">Selesaikan</button></form>@endif
@if($p->status==='on_hold')<form method="POST" action="{{ route('project.transition',$p) }}" class="d-inline">@csrf<input type="hidden" name="to" value="active"><button class="btn btn-sm btn-primary">Lanjutkan</button></form>@endif
<span class="small text-secondary ms-2">Jam {{ $profit[$p->id]['labor_hours'] }} · Biaya Rp {{ number_format($profit[$p->id]['total_cost'],0,',','.') }}</span>
</div></div></div>
@empty<div class="card"><div class="card-body text-center text-secondary">Belum ada proyek.</div></div>@endforelse
</div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Proyek baru</h3></div><div class="card-body"><form method="POST" action="{{ route('project.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="160"></div>
<div class="col-6"><label class="form-label">Anggaran (Rp)</label><input name="budget" type="number" min="0" step="0.01" value="0" class="form-control" required></div>
<div class="col-6"><label class="form-label">Rate/jam (Rp)</label><input name="hourly_rate" type="number" min="0" step="0.01" value="0" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat proyek</button></div></form></div></div></div>
</div>
@endsection
