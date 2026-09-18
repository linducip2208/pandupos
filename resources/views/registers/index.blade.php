@extends('layouts.tabler')

@section('title', 'Register Kasir')
@section('page-title', 'Register & Sesi Kasir')
@section('page-subtitle', 'Kas awal, mutasi kas, penutupan, selisih, dan histori tercatat tanpa menghapus transaksi.')

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger"><strong>Aksi register tidak dapat diproses.</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="row row-cards">
        @if ($canManage)
            <div class="col-12 col-xl-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">Tambah register</h3></div><div class="card-body">
                <form method="POST" action="{{ route('registers.store') }}" class="row g-3">@csrf
                    <div class="col-12"><label class="form-label">Nama register</label><input class="form-control" name="name" maxlength="120" required placeholder="Kasir Utama"></div>
                    <div class="col-12"><label class="form-label">Cabang</label><select class="form-select" name="branch_id"><option value="">Tanpa cabang khusus</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
                    <div class="col-12"><button class="btn btn-primary w-100">Buat register</button></div>
                </form>
            </div></div></div>
        @endif
        <div class="col-12 {{ $canManage ? 'col-xl-8' : '' }}"><div class="card h-100"><div class="card-header"><h3 class="card-title">Register aktif</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Register</th><th>Cabang</th><th>Sesi</th><th>Kas awal</th><th>Aksi</th></tr></thead><tbody>
            @forelse($registers as $register)
                @php($open = $sessions->first(fn ($session) => $session->register_id === $register->id && $session->status === 'open'))
                <tr><td class="fw-semibold">{{ $register->name }} @unless($register->is_active)<span class="badge bg-secondary">Nonaktif</span>@endunless</td><td>{{ $register->branch?->name ?: '—' }}</td><td>@if($open)<span class="badge bg-success">Buka #{{ $open->id }}</span><div class="small text-secondary">{{ $open->openedBy?->name }} · {{ $open->opened_at?->format('d/m H:i') }}</div>@else<span class="text-secondary">Tidak ada sesi</span>@endif</td><td>{{ $open ? 'Rp '.number_format($open->opening_amount, 0, ',', '.') : '—' }}</td><td class="text-nowrap">@if(!$open && $register->is_active && $canOpen)<form method="POST" action="{{ route('registers.open', $register) }}" class="d-flex gap-1">@csrf<input class="form-control form-control-sm" style="width:130px" name="opening_amount" type="number" min="0" step="0.01" required placeholder="Kas awal"><button class="btn btn-sm btn-primary">Buka</button></form>@endif @if(!$open && $register->is_active && $canManage)<form class="mt-1" method="POST" action="{{ route('registers.deactivate', $register) }}">@csrf<button class="btn btn-sm btn-outline-danger">Nonaktifkan</button></form>@endif</td></tr>
            @empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada register. Buat register sebelum membuka sesi kasir.</td></tr>@endforelse
        </tbody></table></div></div></div>
    </div>

    <div class="card mt-3"><div class="card-header"><h3 class="card-title">Sesi dan penutupan kas</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Register</th><th>Status</th><th>Kas</th><th>Mutasi</th><th>Penutupan</th></tr></thead><tbody>
        @forelse($sessions as $session)
            @php($expected = $session->status === 'open' ? ($expectedAmounts[$session->id] ?? 0) : $session->expected_amount)
            <tr><td><div class="fw-semibold">{{ $session->register?->name }}</div><div class="small text-secondary">Dibuka {{ $session->opened_at?->format('d/m/Y H:i') }} · {{ $session->openedBy?->name }}</div></td><td><span class="badge {{ $session->status === 'open' ? 'bg-success' : 'bg-secondary' }}">{{ $session->status === 'open' ? 'Buka' : 'Tutup' }}</span></td><td>Awal Rp {{ number_format($session->opening_amount, 0, ',', '.') }}<br><span class="small text-secondary">Ekspektasi Rp {{ number_format($expected, 0, ',', '.') }}</span>@if($session->status === 'closed')<br><span class="small">Aktual Rp {{ number_format($session->closing_amount, 0, ',', '.') }} · Selisih <span class="{{ $session->variance_amount == 0 ? 'text-success' : 'text-danger' }}">Rp {{ number_format($session->variance_amount, 0, ',', '.') }}</span></span>@endif</td><td>@if($session->status === 'open' && $canOpen)<form method="POST" action="{{ route('registers.movements.store', $session) }}" class="row g-1">@csrf<div class="col-12"><select name="type" class="form-select form-select-sm"><option value="cash_in">Kas masuk</option><option value="cash_out">Kas keluar</option></select></div><div class="col-6"><input class="form-control form-control-sm" name="amount" type="number" min="0.01" step="0.01" placeholder="Nominal" required></div><div class="col-6"><input class="form-control form-control-sm" name="reason" maxlength="1000" placeholder="Alasan wajib" required></div><div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">Catat mutasi</button></div></form>@else<small class="text-secondary">{{ $session->movements->count() }} mutasi tercatat</small>@endif</td><td>@if($session->status === 'open' && $canClose)<form method="POST" action="{{ route('registers.close', $session) }}" class="row g-1">@csrf<div class="col-12"><input class="form-control form-control-sm" name="actual_amount" type="number" min="0" step="0.01" placeholder="Kas aktual" required></div><details class="col-12"><summary class="small">Hitung pecahan (opsional)</summary>@foreach($denominations as $denomination)<label class="input-group input-group-sm mt-1"><span class="input-group-text">Rp {{ number_format($denomination,0,',','.') }}</span><input class="form-control" type="number" min="0" step="1" name="denominations[{{ $denomination }}]" placeholder="0"></label>@endforeach</details><div class="col-12"><input class="form-control form-control-sm" name="notes" maxlength="1000" placeholder="Catatan penutupan"></div><div class="col-12"><button class="btn btn-sm btn-danger w-100">Tutup sesi</button></div></form>@elseif($session->status === 'closed')<small class="text-secondary">Ditutup {{ $session->closed_at?->format('d/m/Y H:i') }} · {{ $session->closedBy?->name }}</small>@endif</td></tr>
        @empty<tr><td colspan="5" class="text-center text-secondary py-5">Belum ada sesi register.</td></tr>@endforelse
    </tbody></table></div></div></div>
@endsection
