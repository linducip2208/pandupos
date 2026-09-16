@extends('layouts.tabler')
@section('title', 'Affiliates')
@section('header', 'Affiliates')
@section('content')
<div class="card mb-3"><div class="card-body">Pending commissions: <strong>{{ number_format($pendingTotal) }}</strong></div></div>
<div class="row row-cards">
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Affiliates</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>User</th><th>Code</th><th>Status</th></tr></thead>
<tbody>@foreach($affiliates as $a)<tr><td>{{ $a->user_id }}</td><td>{{ $a->code }}</td><td>{{ $a->status }}</td></tr>@endforeach</tbody></table></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Commissions</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>Aff</th><th>Amount</th><th>Status</th></tr></thead>
<tbody>@foreach($commissions as $c)<tr><td>{{ $c->affiliate_user_id }}</td><td>{{ number_format($c->commission_amount) }}</td><td>{{ $c->status }}</td></tr>@endforeach</tbody></table></div>
<div class="card-body"><form method="POST" action="{{ route('platform.affiliates.payout') }}" class="row g-2">@csrf<div class="col"><input name="affiliate_user_id" class="form-control" placeholder="user_id"></div><div class="col-auto"><button class="btn btn-primary">Payout</button></div></form></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Payouts</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>User</th><th>Amount</th></tr></thead>
<tbody>@foreach($payouts as $p)<tr><td>{{ $p->id }}</td><td>{{ $p->affiliate_user_id }}</td><td>{{ number_format($p->amount) }}</td></tr>@endforeach</tbody></table></div></div></div>
</div>
@endsection
