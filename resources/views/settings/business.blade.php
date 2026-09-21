@extends('layouts.tabler')
@section('title', 'Fitur Bisnis')
@section('header', 'Pengaturan · Fitur Bisnis')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
<div class="row row-cards"><div class="col-12 col-xl-6"><div class="card"><div class="card-header"><h3 class="card-title">POS / Fitur Bisnis</h3></div><div class="card-body">
<form method="POST" action="{{ route('settings.business.update') }}">@csrf
<label class="form-check form-switch"><input type="hidden" name="restaurant_enabled" value="0"><input class="form-check-input" type="checkbox" name="restaurant_enabled" value="1" @checked($restaurant)><span class="form-check-label"><strong>Aktifkan Fitur Restaurant</strong><small class="d-block text-secondary">Meja, booking, modifier, dapur/KDS. Default MATI — Retail tetap berjalan normal.</small></span></label>
<button class="btn btn-primary mt-3">Simpan</button></form>
</div></div></div></div>
@endsection
