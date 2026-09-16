@extends('layouts.tabler')
@section('title', 'Modules')
@section('header', 'Platform Modules')
@section('content')
@if($errors)<div class="alert alert-danger">@foreach($errors as $e)<div>{{ $e }}</div>@endforeach</div>@endif
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Module</th><th>Version</th><th>Status</th><th>Tenants</th><th>Deps</th></tr></thead>
<tbody>@foreach($modules as $m)<tr><td>{{ $m->name }} ({{ $m->slug }})</td><td>{{ $m->version }}</td><td>{{ $m->status }}</td><td>{{ $m->enabled_tenants }}</td><td>{{ implode(', ', $manifests[$m->slug]['dependencies'] ?? $m->metadata['dependencies'] ?? []) }}</td></tr>@endforeach</tbody></table></div></div>
@endsection
