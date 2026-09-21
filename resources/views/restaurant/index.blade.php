@extends('layouts.tabler')
@section('title', 'Restaurant')
@section('header', 'Restaurant · Meja, Booking & Order')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-7">
@foreach($floors as $f)<div class="card mb-3"><div class="card-header"><h3 class="card-title">Lantai {{ $f->name }}</h3></div><div class="card-body row g-2">
@forelse($f->tables as $t)<div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h3 mb-0">{{ $t->code }}</div><span class="badge">{{ $t->status }}</span><div class="small text-secondary">{{ $t->seats }} kursi</div></div></div></div>
@empty<div class="text-secondary">Belum ada meja.</div>@endforelse
</div></div>@endforeach
<div class="card"><div class="card-header"><h3 class="card-title">Booking</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Waktu</th><th>Tamu/Meja</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($bookings as $b)<tr><td>{{ $b->starts_at->format('d/m H:i') }}</td><td><strong>{{ $b->customer_name }}</strong><div class="small text-secondary">{{ $b->table?->code }}</div></td><td><span class="badge">{{ $b->status }}</span></td><td>
@if($b->status==='booked')<form method="POST" action="{{ route('restaurant.bookings.transition',$b) }}" class="d-inline">@csrf<input type="hidden" name="to" value="seated"><button class="btn btn-sm btn-primary">Duduki</button></form>
<form method="POST" action="{{ route('restaurant.bookings.transition',$b) }}" class="d-inline">@csrf<input type="hidden" name="to" value="cancelled"><button class="btn btn-sm btn-outline-danger">Batal</button></form>@endif
</td></tr>
@empty<tr><td colspan="4" class="text-center text-secondary">Belum ada booking.</td></tr>@endforelse
</tbody></table></div></div>
</div>
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Tiket aktif</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Nomor</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($tickets as $t)<tr><td><strong>{{ $t->number }}</strong><div class="small text-secondary">{{ $t->order_type }} · Rp {{ number_format($t->total,0,',','.') }}</div></td><td><span class="badge">{{ $t->status }}</span></td><td>
<a class="btn btn-sm btn-outline-secondary" href="{{ route('restaurant.kitchen') }}">Dapur</a>
@if(in_array($t->status,['preparing','ready'],true))<details class="d-inline"><summary class="btn btn-sm btn-success">Tutup</summary><form method="POST" action="{{ route('restaurant.tickets.close',$t) }}" class="mt-1">@csrf<input name="payments[0][method]" value="cash" class="form-control form-control-sm mb-1"><input name="payments[0][amount]" type="number" min="0.01" step="0.01" value="{{ $t->total }}" class="form-control form-control-sm mb-1" required><button class="btn btn-sm btn-success w-100">Bayar & tutup</button></form></details>@endif
</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary">Tidak ada tiket aktif.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Order baru (api JSON untuk modifier rinci)</h3></div><div class="card-body"><p class="text-secondary small">Order dengan modifier dikirim via API <code>POST /api/v1/restaurant/tickets/fire</code>. Form ini untuk order cepat tanpa modifier.</p></div></div></div>
</div>
@endsection
