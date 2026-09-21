@extends('layouts.tabler')
@section('title', 'Cek')
@section('header', 'Cek · Terima, Setor, Kliring & Rekonsiliasi')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="card mb-3"><div class="card-body d-flex gap-4 flex-wrap"><div><span class="text-secondary">Kliring</span><div class="h3 mb-0">Rp {{ number_format($recon['cleared'],0,',','.') }}</div></div><div><span class="text-secondary">Beredar</span><div class="h3 mb-0">Rp {{ number_format($recon['outstanding'],0,',','.') }}</div></div><div><span class="text-secondary">Tolak</span><div class="h3 mb-0">Rp {{ number_format($recon['bounced'],0,',','.') }}</div></div></div></div>
<div class="row row-cards">
<div class="col-12 col-xl-8"><div class="card"><div class="card-header"><h3 class="card-title">Daftar cek</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Cek</th><th class="text-end">Nominal</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($cheques as $c)<tr><td><strong>{{ $c->bank_name }} · {{ $c->cheque_no }}</strong><div class="small text-secondary">{{ $c->type }} · {{ $c->contact?->name }} · jatuh {{ $c->due_date->toDateString() }}@if($c->bounce_reason) · tolak: {{ $c->bounce_reason }}@endif</div></td><td class="text-end">Rp {{ number_format($c->amount,0,',','.') }}</td><td><span class="badge">{{ $c->status }}</span></td><td class="text-nowrap">
@if($c->status==='deposited')<form method="POST" action="{{ route('cheque.transition',$c) }}" class="d-inline">@csrf<input type="hidden" name="action" value="clear"><button class="btn btn-sm btn-success">Kliring</button></form>
<details class="d-inline"><summary class="btn btn-sm btn-outline-danger">Tolak</summary><form method="POST" action="{{ route('cheque.transition',$c) }}" class="mt-1">@csrf<input type="hidden" name="action" value="bounce"><input name="reason" class="form-control form-control-sm mb-1" placeholder="Alasan wajib" required><button class="btn btn-sm btn-danger w-100">Catat tolak</button></form></details>@endif
@if(in_array($c->status,['received','issued'],true))<form method="POST" action="{{ route('cheque.transition',$c) }}" class="d-inline">@csrf<input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-outline-secondary">Batal</button></form>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada cek.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Catat cek</h3></div><div class="card-body"><form method="POST" action="{{ route('cheque.store') }}" class="row g-2">@csrf
<div class="col-6"><label class="form-label">Jenis</label><select name="type" class="form-select"><option value="receipt">Terima</option><option value="payment">Bayar</option></select></div>
<div class="col-6"><label class="form-label">Nominal</label><input name="amount" type="number" min="0.01" step="0.01" class="form-control" required></div>
<div class="col-6"><label class="form-label">Bank</label><input name="bank_name" class="form-control" required maxlength="128"></div>
<div class="col-6"><label class="form-label">Nomor cek</label><input name="cheque_no" class="form-control" required maxlength="64"></div>
<div class="col-6"><label class="form-label">Terbit</label><input name="issue_date" type="date" value="{{ now()->toDateString() }}" class="form-control" required></div>
<div class="col-6"><label class="form-label">Jatuh tempo</label><input name="due_date" type="date" class="form-control" required></div>
<div class="col-12"><button class="btn btn-primary w-100">Catat</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Setor ke bank</h3></div><div class="card-body"><form method="POST" action="{{ route('cheque.deposit') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">ID cek (koma)</label><input name="cheque_ids_raw" class="form-control" placeholder="1,2,3"></div>
<div class="col-6"><label class="form-label">Bank</label><input name="bank_name" class="form-control" required></div>
<div class="col-6"><label class="form-label">Tanggal</label><input name="deposited_on" type="date" value="{{ now()->toDateString() }}" class="form-control" required></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat setoran</button></div></form><p class="small text-secondary mt-2">Isi ID cek dipisah koma dari kolom pertama.</p></div></div></div>
</div>
@endsection
