@extends('layouts.tabler')
@section('title', 'Tenants')
@section('header', 'Tenants')
@section('content')
<div class="card"><div class="card-body">
<form method="GET" class="row g-2"><div class="col"><input name="search" value="{{ request('search') }}" class="form-control" placeholder="Search name/slug"></div>
<div class="col-auto"><select name="status" class="form-select"><option value="">All</option>@foreach(['trial','active','past_due','suspended','cancelled','archived'] as $s)<option value="{{ $s }}" @selected(request('status')===$s)>{{ $s }}</option>@endforeach</select></div>
<div class="col-auto"><button class="btn btn-primary">Filter</button></div></form></div>
<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Company</th><th>Status</th><th>Plan</th><th>Created</th></tr></thead>
<tbody>@foreach($tenants as $t)<tr><td>{{ $t->id }}</td><td><a href="{{ route('platform.tenants.show', $t) }}">{{ $t->name }}</a><div class="text-muted">{{ $t->slug }}</div></td><td><span class="badge bg-secondary">{{ $t->status }}</span></td><td>{{ $t->activeSubscription?->plan?->name }}</td><td>{{ $t->created_at }}</td></tr>@endforeach</tbody></table></div>
<div class="card-footer">{{ $tenants->links() }}</div></div>
@endsection
