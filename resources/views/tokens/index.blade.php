@extends('layouts.tabler')

@section('title', 'API Tokens')
@section('page-title', 'API Tokens')
@section('page-subtitle', 'Terbitkan token bertarget dengan masa kedaluwarsa dan cabut kapan saja')

@section('content')
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@if(session('token'))<div class="alert alert-warning" role="alert"><strong>Token baru:</strong> <code>{{ session('token') }}</code></div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
    <div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h2 class="card-title">Terbitkan token</h2></div><div class="card-body"><form method="POST" action="{{ route('tokens.store') }}" class="row g-3">@csrf
        <div class="col-12"><label class="form-label">Nama token</label><input name="name" class="form-control" maxlength="100" required></div>
        <div class="col-12"><label class="form-label">Scopes</label>@foreach($abilities as $ability)<label class="form-check"><input type="checkbox" name="abilities[]" value="{{ $ability }}" class="form-check-input" checked><span class="form-check-label">{{ $ability }}</span></label>@endforeach</div>
        <div class="col-12"><label class="form-label">Kedawaluarsa (hari)</label><input name="expires_days" type="number" min="1" max="365" value="30" class="form-control" required></div>
        <div class="col-12"><button class="btn btn-primary w-100">Terbitkan</button></div>
    </form></div></div></div>
    <div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h2 class="card-title">Token aktif</h2></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Nama</th><th>Scopes</th><th>Kedawaluarsa</th><th>Aksi</th></tr></thead><tbody>
        @forelse($tokens as $token)<tr><td>{{ $token->name }}</td><td class="small">{{ implode(', ', $token->abilities ?? []) }}</td><td class="small">{{ $token->expires_at?->format('d M Y') ?? '—' }}</td><td><form method="POST" action="{{ route('tokens.destroy', $token) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Cabut</button></form></td></tr>
        @empty<tr><td colspan="4" class="text-center text-secondary py-4">Belum ada token.</td></tr>@endforelse
    </tbody></table></div></div></div>
</div>
@endsection
