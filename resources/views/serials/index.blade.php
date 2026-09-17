@extends('layouts.tabler')

@section('title', 'Serial Number')
@section('page-title', 'Serial Number')
@section('page-subtitle', 'Penerimaan serial menghasilkan ledger stok satu unit; riwayat sale dan purchase ditelusuri dari dokumen sumber.')

@section('content')
    @if ($errors->any())<div class="alert alert-danger"><strong>Serial belum dapat diterima.</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="row row-cards">
        <div class="col-12 col-xl-4"><div class="card h-100"><div class="card-header"><h2 class="card-title">Terima serial</h2></div><div class="card-body">
            <form method="POST" action="{{ route('serials.receive') }}" class="row g-3">@csrf
                <div class="col-12"><label class="form-label">Gudang</label><select name="warehouse_id" class="form-select" required><option value="">Pilih gudang</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Produk / varian</label><select name="product_variant_id" class="form-select" required><option value="">Pilih varian</option>@foreach ($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} · {{ $variant->product?->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-7"><label class="form-label">Nomor serial / IMEI</label><input name="serial_number" maxlength="255" class="form-control" required></div><div class="col-12 col-md-5"><label class="form-label">Biaya/unit</label><input name="unit_cost" type="number" min="0" step="0.01" class="form-control" required></div>
                <div class="col-12"><label class="form-label">Batch (opsional)</label><select name="inventory_batch_id" class="form-select"><option value="">Tidak ditautkan</option>@foreach ($batches as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Purchase order (opsional)</label><select name="purchase_id" class="form-select"><option value="">Tidak ditautkan</option>@foreach ($purchases as $purchase)<option value="{{ $purchase->id }}">{{ $purchase->ref_no ?: 'PO #'.$purchase->id }}</option>@endforeach</select></div>
                <div class="col-12"><button class="btn btn-primary w-100">Terima serial ke ledger</button></div>
            </form>
        </div></div></div>
        <div class="col-12 col-xl-8"><div class="card h-100"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h2 class="card-title me-auto">Register serial</h2><form method="GET"><select name="status" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Semua status</option>@foreach ($statuses as $status)<option value="{{ $status }}" @selected($selectedStatus === $status)>{{ ucfirst($status) }}</option>@endforeach</select></form></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Serial</th><th>Produk</th><th>Status & Gudang</th><th>Riwayat</th></tr></thead><tbody>@forelse ($serials as $serial)<tr><td class="fw-semibold">{{ $serial->serial_number }}<div class="text-secondary small">Batch: {{ $serial->inventoryBatch?->batch_number ?: '—' }}</div></td><td>{{ $serial->variant?->product?->name }}<div class="text-secondary small">{{ $serial->variant?->sku }}</div></td><td><span class="badge {{ $serial->status === 'sold' ? 'bg-secondary' : ($serial->status === 'available' ? 'bg-success' : 'bg-warning text-dark') }}">{{ $serial->status }}</span><div class="text-secondary small mt-1">{{ $serial->warehouse?->name }}</div></td><td><div class="small">Beli: {{ $serial->purchase?->ref_no ?: '—' }}</div><div class="small">Jual: {{ $serial->salesInvoice?->invoice_no ?: ($serial->sales_invoice_id ? '#'.$serial->sales_invoice_id : '—') }}</div><div class="text-secondary small">{{ $serial->movements->count() }} movement ledger</div></td></tr>@empty<tr><td colspan="4" class="text-center text-secondary py-5">Belum ada serial pada tenant ini.</td></tr>@endforelse</tbody></table></div></div>
            <div class="card-footer text-secondary small">Return, transfer, dan sale harus berasal dari dokumen transaksi agar status serial dan ledger tidak menyimpang; tombol manual tidak disediakan.</div>
        </div></div>
    </div>
@endsection
