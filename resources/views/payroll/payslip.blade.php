@extends('layouts.tabler')
@section('title', 'Slip Gaji')
@section('header', 'Slip Gaji')
@section('content')
<div class="card"><div class="card-header"><h3 class="card-title">Slip {{ $slip['employee']['name'] }} · {{ $slip['period'] }} <span class="badge">{{ $slip['status'] }}</span></h3><div class="card-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('payroll.index') }}">Kembali</a></div></div><div class="table-responsive"><table class="table card-table"><tbody>
<tr><td>Gaji pokok</td><td class="text-end">{{ number_format($slip['base_salary'],2,',','.') }}</td></tr>
@foreach($slip['allowances'] as $a)<tr><td>Tunjangan · {{ $a['name'] }}</td><td class="text-end">{{ number_format($a['amount'],2,',','.') }}</td></tr>@endforeach
<tr><th>Bruto</th><th class="text-end">{{ number_format($slip['gross'],2,',','.') }}</th></tr>
<tr><td>Pajak</td><td class="text-end">({{ number_format($slip['tax'],2,',','.') }})</td></tr>
@foreach($slip['deductions'] as $d)<tr><td>Potongan · {{ $d['name'] }}</td><td class="text-end">({{ number_format($d['amount'],2,',','.') }})</td></tr>@endforeach
<tr><th>Take-home pay</th><th class="text-end">{{ number_format($slip['net'],2,',','.') }}</th></tr>
<tr><td>Kehadiran</td><td class="text-end">{{ $slip['present_days'] }} hari</td></tr>
</tbody></table></div></div>
@endsection
