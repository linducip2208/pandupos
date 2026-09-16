@extends('layouts.tabler')
@section('title', 'Announcements')
@section('header', 'Communication Center')
@section('content')
<div class="card mb-3"><div class="card-header"><h3 class="card-title">New announcement</h3></div><div class="card-body">
<form method="POST" action="{{ route('platform.announcements.store') }}" class="row g-2">@csrf
<div class="col-md-4"><input name="subject" class="form-control" placeholder="Subject" required></div>
<div class="col-md-4"><input name="audience" class="form-control" placeholder="all|trial|expired|suspended|plan:starter" value="all" required></div>
<div class="col-md-4"><input name="body" class="form-control" placeholder="Body" required></div>
<div class="col-auto"><button class="btn btn-primary">Create</button></div></form></div></div>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>Subject</th><th>Audience</th><th>Sent</th><th></th></tr></thead>
<tbody>@foreach($announcements as $a)<tr><td>{{ $a->id }}</td><td>{{ $a->subject }}</td><td>{{ $a->audience }}</td><td>{{ $a->sent_at ?? '—' }}</td>
<td><form method="POST" action="{{ route('platform.announcements.send', $a) }}">@csrf<button class="btn btn-sm">Send/Queue</button></form></td></tr>@endforeach</tbody></table></div>
<div class="card-footer">{{ $announcements->links() }}</div></div>
@endsection
