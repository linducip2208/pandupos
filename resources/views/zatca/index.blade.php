@extends('layouts.tabler')
@section('title', 'ZATCA')
@section('header', 'ZATCA · E-Invoice, QR & Pelaporan')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8"><div class="card"><div class="card-header"><h3 class="card-title">Dokumen (portal submission live di luar cakupan)</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Dokumen</th><th>QR</th><th>Total / PPN</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($docs as $d)<tr><td><strong>{{ $d->type }}</strong><div class="small text-secondary">{{ $d->seller_name }} · {{ $d->issued_at->toDateTimeString() }}</div><div class="small font-monospace">hash {{ substr($d->hash,0,16) }}…</div></td><td>{!! $qr[$d->id] ?? '' !!}</td><td class="text-end">{{ number_format($d->total,2,',','.') }}<div class="small text-secondary">PPN {{ number_format($d->vat_total,2,',','.') }}</div></td><td><span class="badge">{{ $d->status }}</span>@if($d->clearance_id)<div class="small">{{ $d->clearance_id }}</div>@endif</td><td class="text-nowrap">
@if($d->type==='invoice')<details class="d-inline"><summary class="btn btn-sm btn-outline-secondary">Nota</summary><form method="POST" action="{{ route('zatca.notes.issue') }}" class="mt-1">@csrf<input type="hidden" name="references_document_id" value="{{ $d->id }}"><select name="type" class="form-select form-select-sm mb-1"><option value="credit_note">Kredit</option><option value="debit_note">Debit</option></select><input name="total" type="number" min="0.01" step="0.01" class="form-control form-control-sm mb-1" placeholder="Total" required><input name="vat_total" type="number" min="0" step="0.01" value="0" class="form-control form-control-sm mb-1"><button class="btn btn-sm btn-secondary w-100">Terbitkan</button></form></details>@endif
@if($d->status==='generated')<details class="d-inline"><summary class="btn btn-sm btn-outline-primary">Lapor</summary><form method="POST" action="{{ route('zatca.report',$d) }}" class="mt-1">@csrf<input name="clearance_id" class="form-control form-control-sm mb-1" placeholder="ID clearance portal" required><button class="btn btn-sm btn-primary w-100">Catat</button></form></details>@endif
</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada dokumen.</td></tr>@endforelse
</tbody></table></div></div></div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Buat dari invoice penjualan</h3></div><div class="card-body"><form method="POST" action="{{ route('zatca.generate') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Invoice</label><select name="sales_invoice_id" class="form-select" required>@foreach($invoices as $i)<option value="{{ $i->id }}">{{ $i->invoice_no }} · Rp {{ number_format($i->total,0,',','.') }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Nama penjual</label><input name="seller_name" class="form-control" required maxlength="255"></div>
<div class="col-12"><label class="form-label">NPWP 15 digit</label><input name="seller_vat" class="form-control" required maxlength="32" placeholder="3xxxxxxxxxxxxxx"></div>
<div class="col-12"><button class="btn btn-primary w-100">Buat dokumen</button></div></form></div></div></div>
</div>
@endsection
