@extends('layouts.tabler')
@section('title', 'Dapur / KDS')
@section('header', 'Dapur · Layar KDS')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
@forelse($tickets as $t)<div class="col-12 col-md-6 col-xl-4"><div class="card"><div class="card-header"><h3 class="card-title">{{ $t->number }} · {{ $t->table?->code }} <span class="badge">{{ $t->status }}</span></h3><div class="card-actions"><span class="text-secondary small">{{ gmdate('H:i:s', $t->elapsedSeconds()) }}</span></div></div>
<div class="table-responsive"><table class="table card-table"><tbody>
@foreach($t->items as $i)<tr><td>{{ $i->quantity }}× {{ $i->variant?->sku }}@foreach($i->modifiers ?? [] as $m)<div class="small text-secondary">+ {{ $m['option'] }}</div>@endforeach @if($i->refired)<span class="badge bg-warning">masak ulang</span>@endif</td><td class="text-nowrap">
@if($t->status==='preparing')<details class="d-inline"><summary class="btn btn-sm btn-outline-warning">Ulang</summary><form method="POST" action="{{ route('restaurant.items.refire',$i) }}" class="mt-1">@csrf<input name="reason" class="form-control form-control-sm mb-1" placeholder="Alasan wajib" required><button class="btn btn-sm btn-warning w-100">Masak ulang</button></form></details>@endif
</td></tr>@endforeach
</tbody></table></div>
<div class="card-body border-top d-flex gap-1">
@if($t->status==='queued')<form method="POST" action="{{ route('restaurant.tickets.advance',$t) }}">@csrf<input type="hidden" name="to" value="preparing"><button class="btn btn-sm btn-primary">Masak</button></form>@endif
@if($t->status==='preparing')<form method="POST" action="{{ route('restaurant.tickets.advance',$t) }}">@csrf<input type="hidden" name="to" value="ready"><button class="btn btn-sm btn-success">Siap</button></form>@endif
</div></div></div>
@empty<div class="card"><div class="card-body text-center text-secondary">Dapur kosong. Order masuk tampil di sini.</div></div>@endforelse
</div>
@endsection
