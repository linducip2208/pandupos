@extends('layouts.tabler')
@section('title', 'Billing')
@section('header', 'Billing')
@section('content')
<div class="row row-cards">
<div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title">Invoices</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Tenant</th><th>No</th><th>Total</th><th>Status</th></tr></thead>
<tbody>@foreach($invoices as $i)<tr><td>{{ $i->id }}</td><td>{{ $i->tenant_id }}</td><td>{{ $i->invoice_no }}</td><td>{{ number_format($i->total) }}</td><td>{{ $i->status }}</td></tr>@endforeach</tbody></table></div>
<div class="card-footer">{{ $invoices->links() }}</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header"><h3 class="card-title">Recent transactions</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Gateway</th><th>Amount</th><th>Status</th></tr></thead>
<tbody>@foreach($transactions as $t)<tr><td>{{ $t->id }}</td><td>{{ $t->gateway }}</td><td>{{ number_format($t->amount) }}</td><td>{{ $t->status }}</td></tr>@endforeach</tbody></table></div></div></div>
</div>
@endsection
