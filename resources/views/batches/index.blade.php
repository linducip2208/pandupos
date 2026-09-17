@extends('layouts.tabler')

@section('title', 'Batch & Kedaluwarsa')
@section('page-title', 'Batch, Lot & Kedaluwarsa')
@section('page-subtitle', 'Penerimaan batch tercatat pada ledger; alokasi FEFO hanya memakai stok yang belum kedaluwarsa.')

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger"><strong>Batch belum dapat diterima.</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row row-cards">
        <div class="col-12 col-xl-4">
            <div class="card h-100"><div class="card-header"><h2 class="card-title">Terima batch</h2></div><div class="card-body">
                <form method="POST" action="{{ route('batches.receive') }}" class="row g-3">@csrf
                    <div class="col-12"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required><option value="">Pilih gudang</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Produk / varian</label><select name="product_variant_id" class="form-select" required><option value="">Pilih varian</option>@foreach ($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} · {{ $variant->product?->name }} — {{ $variant->name }}</option>@endforeach</select></div>
                    <div class="col-12 col-md-6"><label class="form-label">Nomor batch / lot</label><input name="batch_number" class="form-control" maxlength="128" required></div>
                    <div class="col-6 col-md-3"><label class="form-label">Jumlah</label><input name="quantity" type="number" min="0.001" step="0.001" class="form-control" required></div>
                    <div class="col-6 col-md-3"><label class="form-label">Biaya/unit</label><input name="unit_cost" type="number" min="0" step="0.01" class="form-control" required></div>
                    <div class="col-6"><label class="form-label">Tgl produksi</label><input name="manufactured_at" type="date" class="form-control"></div>
                    <div class="col-6"><label class="form-label">Tgl kedaluwarsa</label><input name="expires_at" type="date" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Supplier (provenance)</label><select name="supplier_id" class="form-select"><option value="">Tidak ditautkan</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Purchase order (opsional)</label><select name="purchase_id" class="form-select"><option value="">Tidak ditautkan</option>@foreach ($purchases as $purchase)<option value="{{ $purchase->id }}">{{ $purchase->ref_no ?: 'PO #'.$purchase->id }}</option>@endforeach</select></div>
                    <div class="col-12"><button class="btn btn-primary w-100">Terima batch ke ledger</button></div>
                </form>
            </div></div>
        </div>
        <div class="col-12 col-xl-8">
            <div class="card h-100"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><h2 class="card-title me-auto">Stok batch aktif</h2><form method="GET" class="d-flex gap-2"><label class="visually-hidden" for="expiry-days">Jangka waktu</label><select id="expiry-days" name="days" class="form-select form-select-sm" onchange="this.form.submit()">@foreach ([7, 30, 60, 90] as $option)<option value="{{ $option }}" @selected($days === $option)>Kedaluwarsa ≤ {{ $option }} hari</option>@endforeach</select></form></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Batch</th><th>Produk</th><th>Gudang</th><th>Provenance</th><th>Expiry</th><th class="text-end">On hand</th></tr></thead><tbody>
                    @forelse ($batches as $batch)
                        @php $expired = $batch->expires_at?->isBefore(today()); $warning = ! $expired && $batch->expires_at?->lte(today()->addDays($days)); @endphp
                        <tr class="{{ $expired ? 'table-danger' : ($warning ? 'table-warning' : '') }}"><td><div class="fw-semibold">{{ $batch->batch_number }}</div><div class="text-secondary small">{{ $batch->manufactured_at?->format('d M Y') ?: 'Produksi tidak dicatat' }}</div></td><td>{{ $batch->variant?->product?->name }}<div class="text-secondary small">{{ $batch->variant?->sku }}</div></td><td>{{ $batch->warehouse?->name }}</td><td>{{ $batch->supplier?->name ?: '—' }}<div class="text-secondary small">{{ $batch->purchase?->ref_no ?: ($batch->purchase ? 'PO #'.$batch->purchase->id : 'Tanpa PO') }}</div></td><td>@if ($batch->expires_at)<span class="badge {{ $expired ? 'bg-danger' : ($warning ? 'bg-warning text-dark' : 'bg-success') }}">{{ $expired ? 'Kedaluwarsa' : $batch->expires_at->format('d M Y') }}</span>@else<span class="text-secondary">Tidak ada expiry</span>@endif</td><td class="text-end fw-semibold">{{ number_format((float) $batch->on_hand, 3, ',', '.') }}</td></tr>
                    @empty<tr><td colspan="6" class="text-center text-secondary py-5">Belum ada stok batch aktif.</td></tr>@endforelse
                </tbody></table></div></div>
                <div class="card-footer text-secondary small">FEFO mengalokasikan expiry paling awal. Batch kedaluwarsa tidak dapat dijual tanpa override berizin; override tidak tersedia dari halaman ini.</div>
            </div>
        </div>
    </div>
@endsection
