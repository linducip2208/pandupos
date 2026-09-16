@extends('layouts.tabler')
@section('title', 'Masuk — PanduPOS')
@section('header', 'Masuk')
@section('content')
<div class="row justify-content-center"><div class="col-md-5"><div class="card"><div class="card-body">
    <h2 class="card-title">Masuk PanduPOS</h2>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.attempt') }}">@csrf
        <div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required autofocus></div>
        <div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>
        <button class="btn btn-primary w-100">Masuk</button>
    </form>
</div></div></div></div>
@endsection
