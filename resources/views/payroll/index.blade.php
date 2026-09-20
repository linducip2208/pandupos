@extends('layouts.tabler')
@section('title', 'Payroll')
@section('header', 'Payroll · Struktur, Run & Slip Gaji')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-8">
@forelse($runs as $r)<div class="card mb-3"><div class="card-header"><h3 class="card-title">Periode {{ $r->period }} <span class="badge">{{ $r->status }}</span></h3><div class="card-actions"><span class="text-secondary small">Bruto Rp {{ number_format($totals[$r->id]['gross'],0,',','.') }} · Neto Rp {{ number_format($totals[$r->id]['net'],0,',','.') }}</span></div></div>
<div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Karyawan</th><th class="text-end">Bruto</th><th class="text-end">Pajak</th><th class="text-end">Neto</th><th></th></tr></thead><tbody>
@foreach($r->lines as $l)<tr><td>{{ $l->employee?->name }}<div class="small text-secondary">Hadir {{ $l->present_days }} hari</div></td><td class="text-end">{{ number_format($l->gross,0,',','.') }}</td><td class="text-end">{{ number_format($l->tax,0,',','.') }}</td><td class="text-end fw-bold">{{ number_format($l->net,0,',','.') }}</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('payroll.payslip',[$r,$l->employee_id]) }}">Slip</a></td></tr>@endforeach
</tbody></table></div>
<div class="card-body border-top">
@if($r->status==='draft')<form method="POST" action="{{ route('payroll.approve',$r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success" onclick="return confirm('Setujui run ini? Baris terkunci dan diposting ke akuntansi bila aktif.')">Setujui</button></form>@endif
@if($r->status==='approved')<form method="POST" action="{{ route('payroll.paid',$r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Tandai lunas</button></form>@endif
</div></div>
@empty<div class="card"><div class="card-body text-center text-secondary">Belum ada payroll run.</div></div>@endforelse
</div>
<div class="col-12 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">Struktur gaji</h3></div><div class="card-body"><form method="POST" action="{{ route('payroll.structures.store') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Karyawan</label><select name="employee_id" class="form-select" required>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->code }} · {{ $e->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Gaji pokok</label><input name="base_salary" type="number" min="0" step="0.01" value="0" class="form-control" required></div>
<div class="col-6"><label class="form-label">Pajak (0–1)</label><input name="income_tax_rate" type="number" min="0" max="1" step="0.0001" value="0" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan struktur</button></div></form><p class="small text-secondary mt-2">Tunjangan/potongan rinci dikelola via API.</p></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Run baru</h3></div><div class="card-body"><form method="POST" action="{{ route('payroll.runs.store') }}" class="d-flex gap-1">@csrf<input name="period" class="form-control" placeholder="YYYY-MM" pattern="\d{4}-(0[1-9]|1[0-2])" required><button class="btn btn-primary">Buat</button></form></div></div></div>
</div>
@endsection
