@extends('layouts.tabler')
@section('title', 'Akuntansi')
@section('header', 'Akuntansi · Bagan Akun & Neraca Saldo')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Bagan akun</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Kode</th><th>Nama</th><th>Tipe</th><th>Kas</th><th>Status</th></tr></thead><tbody>
@forelse($accounts as $a)<tr><td class="fw-semibold">{{ $a->code }}</td><td>{{ $a->name }} @if($a->is_system)<span class="badge bg-secondary">sistem</span>@endif</td><td>{{ $a->type }}</td><td>{{ $a->is_cash ? 'Ya' : '—' }}</td><td>{{ $a->is_active ? 'Aktif' : 'Nonaktif' }}</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada akun.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Tambah akun</h3></div><div class="card-body"><form method="POST" action="{{ route('accounting.accounts.store') }}" class="row g-2">@csrf
<div class="col-4"><label class="form-label">Kode</label><input name="code" class="form-control" required maxlength="32"></div>
<div class="col-8"><label class="form-label">Nama</label><input name="name" class="form-control" required maxlength="128"></div>
<div class="col-6"><label class="form-label">Tipe</label><select name="type" class="form-select">@foreach(['asset'=>'Aset','liability'=>'Liabilitas','equity'=>'Ekuitas','income'=>'Pendapatan','expense'=>'Beban'] as $v=>$l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Akun kas?</label><select name="is_cash" class="form-select"><option value="0">Bukan</option><option value="1">Ya</option></select></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan akun</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Neraca saldo per {{ $asOf }}</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Kode</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr></thead><tbody>
@foreach($trial['rows'] as $r)<tr><td>{{ $r['code'] }} <span class="text-secondary small">{{ $r['name'] }}</span></td><td class="text-end">{{ number_format($r['debit'],2,',','.') }}</td><td class="text-end">{{ number_format($r['credit'],2,',','.') }}</td></tr>@endforeach
</tbody><tfoot><tr><th>Total</th><th class="text-end">{{ number_format($trial['total_debit'],2,',','.') }}</th><th class="text-end">{{ number_format($trial['total_credit'],2,',','.') }}</th></tr></tfoot></table></div>
<div class="card-footer">@if($trial['balanced'])<span class="badge bg-success">Seimbang</span>@else<span class="badge bg-danger">Tidak seimbang</span>@endif
<a class="btn btn-sm btn-outline-primary ms-2" href="{{ route('accounting.reports') }}">Laporan lengkap</a></div></div></div>
</div>
@endsection
