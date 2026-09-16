@extends('layouts.tabler')
@section('title', 'Tenant detail')
@section('header', $tenant->name)
@section('content')
<div class="row row-cards">
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Company</h3></div><div class="card-body">
<p><strong>Status:</strong> {{ $tenant->status }} | <strong>Plan:</strong> {{ $tenant->activeSubscription?->plan?->name }} ({{ $tenant->activeSubscription?->status }})</p>
<p><strong>Branches:</strong> {{ $tenant->branches->count() }} | <strong>Warehouses:</strong> {{ $tenant->warehouses->count() }} | <strong>Users:</strong> {{ $tenant->memberships->count() }}</p>
<p><strong>Created:</strong> {{ $tenant->created_at }}</p></div>
<div class="card-footer d-flex gap-2 flex-wrap">
<form method="POST" action="{{ route('platform.tenants.activate', $tenant) }}">@csrf<button class="btn btn-success">Activate</button></form>
<form method="POST" action="{{ route('platform.tenants.suspend', $tenant) }}">@csrf<input name="reason" placeholder="reason" class="form-control d-inline w-auto"><button class="btn btn-warning">Suspend</button></form>
<form method="POST" action="{{ route('platform.tenants.archive', $tenant) }}">@csrf<button class="btn btn-danger" onclick="return confirm('Archive?')">Archive</button></form>
<form method="POST" action="{{ route('platform.tenants.impersonate', $tenant) }}">@csrf<button class="btn btn-info">Impersonate</button></form>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Change plan / Extend</h3></div><div class="card-body">
<form method="POST" action="{{ route('platform.tenants.plan', $tenant) }}" class="row g-2">@csrf
<div class="col"><input name="plan_id" class="form-control" placeholder="plan_id"></div>
<div class="col"><select name="billing_cycle" class="form-select"><option>monthly</option><option>yearly</option></select></div>
<div class="col-auto"><button class="btn btn-primary">Change</button></div></form>
<form method="POST" action="{{ route('platform.tenants.extend', $tenant) }}" class="row g-2 mt-2">@csrf
<div class="col"><input name="days" type="number" value="30" class="form-control"></div><div class="col-auto"><button class="btn">Extend</button></div></form>
</div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Subscriptions</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Plan</th><th>Status</th><th>Period end</th></tr></thead>
<tbody>@foreach($tenant->subscriptions as $s)<tr><td>{{ $s->id }}</td><td>{{ $s->plan?->name }}</td><td>{{ $s->status }}</td><td>{{ $s->current_period_end }}</td></tr>@endforeach</tbody></table></div></div>
</div></div>
@endsection
