@extends('layouts.tabler')
@section('title', 'Sales Order')
@section('header', 'Sales Order')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h2 class="card-title">Buat Sales Order</h2></div><form class="card-body row g-3" method="POST" action="{{ route('sales-orders.store') }}">@csrf
<div class="col-6"><label class="form-label">Cabang</label><select class="form-select" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Gudang</label><select class="form-select" name="warehouse_id" required>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Pelanggan</label><select class="form-select" name="contact_id" required>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Produk / varian</label><select class="form-select" name="product_variant_id" required>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} · {{ $variant->product?->name }} (dasar {{ $variant->product?->unit?->short_name ?? '—' }})</option>@endforeach</select></div>
<div class="col-4"><label class="form-label">Jumlah</label><input class="form-control" name="quantity" type="number" min=".001" step=".001" required></div>
<div class="col-4"><label class="form-label">Satuan</label><select class="form-select" name="unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->short_name }}</option>@endforeach</select></div>
<div class="col-4"><label class="form-label">Harga/satuan</label><input class="form-control" name="unit_price" type="number" min="0" step=".01" required></div>
<div class="col-6"><label class="form-label">Tanggal</label><input class="form-control" name="order_date" type="date" value="{{ today()->toDateString() }}" required></div>
<div class="col-12"><label class="form-label">Catatan</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
<div class="col-12"><button type="button" id="add-order-line" class="btn btn-outline-secondary w-100">Tambah baris produk</button></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan draft</button></div>
</form></div></div>
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h2 class="card-title">Order terbaru</h2></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Nomor</th><th>Pelanggan</th><th>Item</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($orders as $order)<tr><td>{{ $order->order_no }}</td><td>{{ $order->contact?->name }}</td><td>@foreach($order->lines as $line)<div>{{ $line->variant?->sku }} · {{ number_format((float) $line->quantity, 3, ',', '.') }} {{ $line->variant?->product?->unit?->short_name }}</div>@endforeach</td><td>Rp {{ number_format((float) $order->total, 0, ',', '.') }}</td><td><span class="badge">{{ $order->status }}</span></td><td class="text-nowrap">@if($order->status === 'draft')<form class="d-inline" method="POST" action="{{ route('sales-orders.confirm', $order) }}">@csrf<button class="btn btn-sm btn-primary">Konfirmasi</button></form>@elseif(in_array($order->status, ['confirmed', 'partial'], true))<form class="d-inline" method="POST" action="{{ route('sales-orders.deliver', $order) }}">@csrf @foreach($order->lines as $line) @php($remaining = max(0, (float) $line->quantity - (float) $line->fulfilled_quantity)) @if($remaining > 0)<input type="hidden" name="lines[{{ $line->id }}][sales_order_line_id]" value="{{ $line->id }}"><input type="hidden" name="lines[{{ $line->id }}][quantity]" value="{{ $remaining }}">@endif @endforeach<button class="btn btn-sm btn-success">Kirim sisa</button></form>@endif @if(in_array($order->status, ['draft', 'confirmed'], true))<form class="d-inline" method="POST" action="{{ route('sales-orders.cancel', $order) }}">@csrf<button class="btn btn-sm btn-outline-danger">Batalkan</button></form>@endif</td></tr>@empty<tr><td colspan="6" class="text-center text-secondary py-5">Belum ada Sales Order.</td></tr>@endforelse</tbody></table></div></div></div>
</div>
<div class="card mt-3"><div class="card-header"><h2 class="card-title">Invoice dari order selesai</h2></div><div class="card-body"><div class="d-flex flex-wrap gap-2">@foreach($orders->where('status', 'fulfilled') as $order)@if($order->invoice)<div class="border rounded p-2"><strong>{{ $order->invoice->invoice_no }}</strong><div class="small text-secondary">{{ $order->order_no }} · total Rp {{ number_format((float) $order->invoice->total, 0, ',', '.') }} · {{ $order->invoice->payment_status }}</div>@if($order->invoice->payment_status !== 'paid')<form method="POST" action="{{ route('sales-orders.invoices.payments.store', $order->invoice) }}" class="d-flex gap-1 mt-2">@csrf<input name="amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm" placeholder="Nominal" required><select name="method" class="form-select form-select-sm"><option value="transfer">Transfer</option><option value="cash">Tunai</option><option value="qris">QRIS</option><option value="ewallet">E-Wallet</option><option value="card">Card</option></select><input name="reference" class="form-control form-control-sm" placeholder="Referensi"><button class="btn btn-sm btn-success">Bayar</button></form>@endif</div>@else<form method="POST" action="{{ route('sales-orders.invoice', $order) }}">@csrf<button class="btn btn-sm btn-outline-primary">Buat invoice {{ $order->order_no }}</button></form>@endif @endforeach</div></div></div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action="{{ route('sales-orders.store') }}"]');
    const add = document.getElementById('add-order-line');
    const fields = ['product_variant_id', 'quantity', 'unit_id', 'unit_price'];
    const primary = fields.map((name) => form?.querySelector(`[name="${name}"]`));
    let extraLines = 0;

    add?.addEventListener('click', () => {
        const index = extraLines + 1;
        const wrapper = document.createElement('div');
        wrapper.className = 'col-12 border rounded p-2 order-line-extra';
        wrapper.innerHTML = `<div class="row g-2"><div class="col-12 col-md-5"><label class="form-label">Produk / varian</label>${primary[0].outerHTML}</div><div class="col-4 col-md-2"><label class="form-label">Jumlah</label>${primary[1].outerHTML}</div><div class="col-4 col-md-2"><label class="form-label">Satuan</label>${primary[2].outerHTML}</div><div class="col-4 col-md-2"><label class="form-label">Harga</label>${primary[3].outerHTML}</div><div class="col-12 col-md-1 d-flex align-items-end"><button type="button" class="btn btn-outline-danger remove-line">Hapus</button></div></div>`;
        wrapper.querySelectorAll('select,input').forEach((input) => {
            const name = input.name;
            input.name = `lines[${index}][${name}]`;
            if (input.tagName === 'INPUT') input.value = '';
        });
        wrapper.querySelector('.remove-line').addEventListener('click', () => wrapper.remove());
        add.closest('.col-12').before(wrapper);
        extraLines++;
    });

    form?.addEventListener('submit', () => {
        if (extraLines === 0) return;
        primary.forEach((input) => { input.name = `lines[0][${input.name}]`; });
    });

    document.querySelectorAll('form[action*="/deliver"]').forEach((deliveryForm) => {
        deliveryForm.querySelectorAll('input[name$="[quantity]"]').forEach((input) => {
            input.type = 'number'; input.min = '0.001'; input.step = '0.001'; input.className = 'form-control form-control-sm d-inline-block me-1'; input.style.width = '5.5rem';
            input.setAttribute('aria-label', 'Jumlah kirim parsial');
        });
    });
});
</script>
@endpush
