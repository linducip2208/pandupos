@extends('layouts.tabler')
@section('title', 'Subscriptions')
@section('header', 'Subscriptions')
@section('content')
<div class="card"><div class="card-body"><form method="GET" class="row g-2"><div class="col-auto"><select name="status" class="form-select"><option value="">All</option>@foreach(['trialing','pending','active','past_due','grace_period','suspended','cancelled','expired'] as $s)<option @selected(request('status')===$s)>{{ $s }}</option>@endforeach</select></div><div class="col-auto"><button class="btn btn-primary">Filter</button></div></form></div>
<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Period end</th></tr></thead>
<tbody>@foreach($subs as $s)<tr><td>{{ $s->id }}</td><td>{{ $s->tenant?->name }} ({{ $s->tenant_id }})</td><td>{{ $s->plan?->name }}</td><td>{{ $s->status }}</td><td>{{ $s->current_period_end }}</td></tr>@endforeach</tbody></table></div>
<div class="card-footer">{{ $subs->links() }}</div></div>
@endsection
