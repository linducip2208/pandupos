@extends('layouts.tabler')
@section('title', 'Audit')
@section('header', 'Audit log')
@section('content')
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Tenant</th><th>Actor</th><th>Action</th><th>Subject</th><th>Time</th></tr></thead>
<tbody>@foreach($logs as $l)<tr><td>{{ $l->id }}</td><td>{{ $l->tenant_id }}</td><td>{{ $l->actor_id }}</td><td>{{ $l->action }}</td><td>{{ $l->subject_type }} #{{ $l->subject_id }}</td><td>{{ $l->created_at }}</td></tr>@endforeach</tbody></table></div>
<div class="card-footer">{{ $logs->links() }}</div></div>
@endsection
