@extends('layouts.tabler')
@section('title', $title)
@section('header', $title)
@section('content')
<div class="card"><div class="card-body"><p class="text-muted">Modul {{ $title }} tersedia via API dan akan dilengkapi bertahap. Fokus saat ini: tenants, plans, modules, audit, health.</p>
<a href="{{ route('platform.dashboard') }}" class="btn btn-primary">Kembali ke dashboard</a></div></div>
@endsection
