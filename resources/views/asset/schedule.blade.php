@extends('layouts.tabler')
@section('title', 'Jadwal Penyusutan')
@section('header', 'Aset · Jadwal Penyusutan')
@section('content')
<div class="card"><div class="card-header"><h3 class="card-title">{{ $asset->code }} · {{ $asset->name }}</h3><div class="card-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('asset.index') }}">Kembali</a></div></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Bulan</th><th class="text-end">Penyusutan</th><th class="text-end">Akumulasi</th><th class="text-end">Nilai buku</th></tr></thead><tbody>
@forelse($rows as $r)<tr><td>{{ $r['period'] }}</td><td class="text-end">{{ number_format($r['depreciation'],2,',','.') }}</td><td class="text-end">{{ number_format($r['accumulated'],2,',','.') }}</td><td class="text-end fw-bold">{{ number_format($r['book'],2,',','.') }}</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Belum ada bulan berjalan.</td></tr>@endforelse
</tbody></table></div></div>
@endsection
