@extends('layouts.tabler')
@section('title', 'Plans')
@section('header', 'Plans & Entitlements')
@section('content')
<div class="card mb-3"><div class="card-header"><h3 class="card-title">Matrix</h3></div>
<div class="table-responsive"><table class="table"><thead><tr><th>Entitlement</th>@foreach($plans as $p)<th>{{ $p->name }}</th>@endforeach</tr></thead>
<tbody>
@foreach($matrixKeys as $k)<tr><td>{{ $k }}</td>@foreach($plans as $p)<td>{{ $p->entitlements->firstWhere('entitlement', $k)?->value === '1' ? '✓' : '—' }}</td>@endforeach</tr>@endforeach
@foreach($numericKeys as $k)<tr><td>{{ $k }}</td>@foreach($plans as $p)<td>{{ $p->entitlements->firstWhere('entitlement', $k)?->value ?? '∞' }}</td>@endforeach</tr>@endforeach
</tbody></table></div></div>
@foreach($plans as $plan)
<div class="card mb-3"><div class="card-header"><h3 class="card-title">{{ $plan->name }} ({{ $plan->slug }})</h3></div>
<div class="card-body"><form method="POST" action="{{ route('platform.plans.entitlements', $plan) }}" class="row g-2">@csrf
@foreach(array_merge($matrixKeys, $numericKeys) as $k)
<div class="col-md-4"><label class="form-label">{{ $k }}</label><input name="entitlements[{{ $k }}]" value="{{ $plan->entitlements->firstWhere('entitlement', $k)?->value }}" class="form-control" placeholder="null = unlimited"></div>
@endforeach
<div class="col-12"><button class="btn btn-primary">Save entitlements</button></div></form></div></div>
@endforeach
@endsection
