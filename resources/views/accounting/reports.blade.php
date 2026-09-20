@extends('layouts.tabler')
@section('title', 'Laporan Keuangan')
@section('header', 'Akuntansi · Laporan Keuangan')
@section('content')
<div class="card mb-3"><div class="card-body"><form method="GET" action="{{ route('accounting.reports') }}" class="row g-2"><div class="col-auto"><label class="form-label">Dari</label><input name="from" type="date" value="{{ $from }}" class="form-control"></div><div class="col-auto"><label class="form-label">Sampai</label><input name="to" type="date" value="{{ $to }}" class="form-control"></div><div class="col-auto d-flex align-items-end"><button class="btn btn-primary">Tampilkan</button></div></form></div></div>
<div class="row row-cards">
<div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Laba rugi {{ $from }} s.d. {{ $to }}</h3></div><div class="table-responsive"><table class="table card-table"><tbody>
@foreach($pnl['income'] as $r)<tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'],2,',','.') }}</td></tr>@endforeach
<tr><th>Total pendapatan</th><th class="text-end">{{ number_format($pnl['total_income'],2,',','.') }}</th></tr>
@foreach($pnl['expenses'] as $r)<tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="text-end">({{ number_format($r['amount'],2,',','.') }})</td></tr>@endforeach
<tr><th>Total beban</th><th class="text-end">({{ number_format($pnl['total_expense'],2,',','.') }})</th></tr>
<tr><th>Laba bersih</th><th class="text-end">{{ number_format($pnl['net_income'],2,',','.') }}</th></tr>
</tbody></table></div></div></div>
<div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Neraca per {{ $to }}</h3></div><div class="table-responsive"><table class="table card-table"><tbody>
<tr><td colspan="2"><strong>Aset</strong></td></tr>
@foreach($balance['assets'] as $r)<tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'],2,',','.') }}</td></tr>@endforeach
<tr><th>Total aset</th><th class="text-end">{{ number_format($balance['total_assets'],2,',','.') }}</th></tr>
<tr><td colspan="2"><strong>Liabilitas</strong></td></tr>
@foreach($balance['liabilities'] as $r)<tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'],2,',','.') }}</td></tr>@endforeach
<tr><th>Total liabilitas</th><th class="text-end">{{ number_format($balance['total_liabilities'],2,',','.') }}</th></tr>
<tr><td colspan="2"><strong>Ekuitas</strong></td></tr>
@foreach($balance['equity'] as $r)<tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="text-end">{{ number_format($r['amount'],2,',','.') }}</td></tr>@endforeach
<tr><td>Laba berjalan</td><td class="text-end">{{ number_format($balance['current_earnings'],2,',','.') }}</td></tr>
<tr><th>Total ekuitas + laba</th><th class="text-end">{{ number_format($balance['total_equity'] + $balance['current_earnings'],2,',','.') }}</th></tr>
</tbody></table></div><div class="card-footer">@if($balance['balanced'])<span class="badge bg-success">Aset = Liabilitas + Ekuitas</span>@else<span class="badge bg-danger">Tidak seimbang</span>@endif</div></div></div>
<div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Arus kas {{ $from }} s.d. {{ $to }}</h3></div><div class="table-responsive"><table class="table card-table"><tbody>
@foreach($cash['cash_accounts'] as $r)<tr><td>{{ $r['code'] }} · {{ $r['name'] }}</td><td class="text-end">+{{ number_format($r['inflow'],2,',','.') }}</td><td class="text-end">-{{ number_format($r['outflow'],2,',','.') }}</td></tr>@endforeach
<tr><th>Neto</th><th class="text-end" colspan="2">{{ number_format($cash['net'],2,',','.') }}</th></tr>
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Pajak {{ $from }} s.d. {{ $to }}</h3></div><div class="card-body">Keluaran Rp {{ number_format($tax['output_tax'],2,',','.') }} · Masukan Rp {{ number_format($tax['input_tax'],2,',','.') }} · <strong>Neto bayar Rp {{ number_format($tax['net_payable'],2,',','.') }}</strong></div></div></div>
<div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">Piutang usaha (belum lunas)</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Dibayar</th><th class="text-end">Saldo</th></tr></thead><tbody>
@forelse($receivables as $r)<tr><td>{{ $r['invoice_no'] }}</td><td class="text-end">{{ number_format($r['total'],2,',','.') }}</td><td class="text-end">{{ number_format($r['paid'],2,',','.') }}</td><td class="text-end fw-bold">{{ number_format($r['balance'],2,',','.') }}</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Tidak ada piutang terbuka.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Hutang usaha (bersaldo)</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Dibayar</th><th class="text-end">Saldo</th></tr></thead><tbody>
@forelse($payables as $r)<tr><td>{{ $r['invoice_number'] }}</td><td class="text-end">{{ number_format($r['total'],2,',','.') }}</td><td class="text-end">{{ number_format($r['paid'],2,',','.') }}</td><td class="text-end fw-bold">{{ number_format($r['balance'],2,',','.') }}</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Tidak ada hutang bersaldo.</td></tr>@endforelse
</tbody></table></div></div></div>
</div>
@endsection
