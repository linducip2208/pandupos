@extends('layouts.tabler')
@section('title', 'Platform Dashboard')
@section('header', 'Platform Admin')
@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Total Tenants</div><div class="h1">{{ $tenantsTotal }}</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Active / Trial / Suspended</div><div class="h1">{{ $tenantsActive }} / {{ $tenantsTrial }} / {{ $tenantsSuspended }}</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">MRR / ARR</div><div class="h1">{{ number_format($mrr) }} / {{ number_format($arr) }}</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="subheader">Revenue (month)</div><div class="h1">{{ number_format($monthRevenue) }}</div></div></div></div>
</div>
<div class="row row-cards">
    <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Latest tenants</h3></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Name</th><th>Status</th><th>Plan</th></tr></thead>
        <tbody>@foreach($latestTenants as $t)<tr><td><a href="{{ route('platform.tenants.show', $t) }}">{{ $t->name }}</a></td><td>{{ $t->status }}</td><td>{{ $t->activeSubscription?->plan?->name }}</td></tr>@endforeach</tbody></table></div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Plan distribution</h3></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Plan</th><th>Total</th></tr></thead>
        <tbody>@foreach($planDistribution as $p)<tr><td>{{ $p->plan }}</td><td>{{ $p->total }}</td></tr>@endforeach</tbody></table></div></div></div>
</div>
<div class="mt-3 d-flex gap-2">
    <a class="btn btn-primary" href="{{ route('platform.tenants.index') }}">Tenants</a>
    <a class="btn" href="{{ route('platform.plans.index') }}">Plans</a>
    <a class="btn" href="{{ route('platform.modules.index') }}">Modules</a>
    <a class="btn" href="{{ route('platform.health') }}">Health</a>
</div>
@endsection
