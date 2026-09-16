@extends('layouts.tabler')
@section('title', 'Health')
@section('header', 'System Health')
@section('content')
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
<tbody>@foreach($checks as $c)<tr><td>{{ $c['label'] }}</td><td><span class="badge bg-{{ $c['ok'] ? 'success' : 'danger' }}">{{ $c['ok'] ? 'OK' : 'FAIL' }}</span></td><td class="text-muted">{{ $c['detail'] }}</td></tr>@endforeach</tbody></table></div></div>
@endsection
