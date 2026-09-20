@extends('layouts.tabler')
@section('title', 'Periode Akuntansi')
@section('header', 'Akuntansi · Periode & Tutup Buku')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Periode</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Mulai</th><th>Selesai</th><th>Status</th><th>Ditutup</th></tr></thead><tbody>
@forelse($periods as $p)<tr><td>{{ $p->starts_on->toDateString() }}</td><td>{{ $p->ends_on->toDateString() }}</td><td><span class="badge">{{ $p->status }}</span></td><td>{{ $p->closed_at?->toDateTimeString() ?? '—' }}</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Belum ada periode. Semua tanggal dapat diposting.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Tutup periode</h3></div><div class="card-body"><p class="text-secondary">Periode yang ditutup menolak posting dan pembatalan pada rentang tanggalnya. Tindakan ini tercatat di audit log.</p><form method="POST" action="{{ route('accounting.periods.close') }}" class="row g-2">@csrf
<div class="col-6"><label class="form-label">Mulai</label><input name="starts_on" type="date" class="form-control" required></div>
<div class="col-6"><label class="form-label">Selesai</label><input name="ends_on" type="date" class="form-control" required></div>
<div class="col-12"><button class="btn btn-danger w-100" onclick="return confirm('Tutup periode ini? Posting pada rentangnya akan ditolak.')">Tutup periode</button></div></form></div></div></div>
</div>
@endsection
