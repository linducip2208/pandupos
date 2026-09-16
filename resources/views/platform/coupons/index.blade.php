@extends('layouts.tabler')
@section('title', 'Coupons')
@section('header', 'Coupons')
@section('content')
<div class="card mb-3"><div class="card-header"><h3 class="card-title">New coupon</h3></div><div class="card-body">
<form method="POST" action="{{ route('platform.coupons.store') }}" class="row g-2">@csrf
<div class="col-md-3"><input name="code" class="form-control" placeholder="CODE" required></div>
<div class="col-md-2"><select name="discount_type" class="form-select"><option value="fixed">fixed</option><option value="percentage">percentage</option></select></div>
<div class="col-md-2"><input name="discount_value" type="number" step="0.01" class="form-control" placeholder="value" required></div>
<div class="col-md-2"><input name="max_redemptions" type="number" class="form-control" placeholder="max"></div>
<div class="col-auto"><button class="btn btn-primary">Create</button></div></form></div></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Used</th><th>Active</th></tr></thead>
<tbody>@foreach($coupons as $c)<tr><td>{{ $c->code }}</td><td>{{ $c->discount_type }}</td><td>{{ $c->discount_value }}</td><td>{{ $c->redemptions_count }}</td><td>{{ $c->is_active ? 'yes' : 'no' }}</td></tr>@endforeach</tbody></table></div>
<div class="card-footer">{{ $coupons->links() }}</div></div>
@endsection
