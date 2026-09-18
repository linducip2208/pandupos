@extends('layouts.tabler')

@section('header', 'Purchasing Workspace')
@section('title', 'Purchasing Workspace')
@section('page-title', 'Purchasing Workspace')
@section('page-subtitle', 'Purchase order, penerimaan, tagihan pemasok, pembayaran, dan return')

@section('content')
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><strong>Data belum dapat diproses.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="row row-cards mb-4">
    @foreach([
        ['PO aktif', $purchases->whereNotIn('status',['received','cancelled'])->count(), 'primary'],
        ['Menunggu approval', $purchases->where('status','pending_approval')->count(), 'orange'],
        ['Utang pemasok', 'Rp '.number_format($invoices->sum(fn($invoice)=>(float)$invoice->balance),0,',','.'), 'red'],
        ['Purchase return', $returns->count(), 'purple'],
    ] as [$label,$value,$tone])
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-secondary small text-uppercase fw-bold">{{ $label }}</div><div class="h2 fw-bold text-{{ $tone }} mb-0">{{ $value }}</div></div></div></div>
    @endforeach
</div>

<div class="row row-cards">
    <div class="col-12 col-xl-5"><div class="card h-100"><div class="card-header"><h2 class="card-title">Buat purchase order</h2></div><div class="card-body">
        <p class="text-secondary">PO tidak menambah stok. Jika melampaui ambang tenant, dokumen otomatis masuk antrean approval.</p>
        <form method="POST" action="{{ route('purchasing.orders.store') }}" class="row g-3">@csrf
            <div class="col-12 col-md-6"><label class="form-label">Pemasok</label><select name="contact_id" class="form-select" required><option value="">Pilih pemasok</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
            <div class="col-12 col-md-6"><label class="form-label">Gudang tujuan</label><select name="warehouse_id" class="form-select" required><option value="">Pilih gudang</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label">Produk/varian</label><select name="product_variant_id" class="form-select" required>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} · {{ $variant->product?->name }} (dasar: {{ $variant->product?->unit?->short_name ?? '—' }})</option>@endforeach</select></div>
            <div class="col-4"><label class="form-label">Jumlah</label><input name="quantity" type="number" min="0.001" step="0.001" class="form-control" required></div>
            <div class="col-4"><label class="form-label">Satuan beli</label><select name="unit_id" class="form-select" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->short_name }})</option>@endforeach</select></div>
            <div class="col-4"><label class="form-label">Harga/satuan</label><input name="unit_cost" type="number" min="0" step="0.01" class="form-control" required></div>
            <div class="col-12"><button class="btn btn-primary w-100">Buat purchase order</button></div>
        </form>
    </div></div></div>

    <div class="col-12 col-xl-7"><div class="card h-100"><div class="card-header"><h2 class="card-title">Purchase order & goods receipt</h2></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>PO</th><th>Pemasok</th><th>Progress</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        @forelse($purchases as $purchase)<tr><td><strong>#{{ $purchase->id }}</strong><div class="small text-secondary">Rp {{ number_format((float)$purchase->total,0,',','.') }}</div></td><td>{{ $purchase->contact?->name }}<div class="small text-secondary">{{ $purchase->warehouse?->name }}</div></td><td>@foreach($purchase->lines as $line)<div class="small">{{ $line->variant?->sku }}: {{ number_format((float)$line->received_quantity,3,',','.') }}/{{ number_format((float)$line->quantity,3,',','.') }}</div>@endforeach</td><td><span class="badge">{{ str_replace('_',' ',$purchase->status) }}</span>@if($purchase->approval_level)<div class="small text-secondary mt-1">Level {{ $purchase->approval_level }}</div>@endif</td><td>
            @if(in_array($purchase->status,['ordered','partial'],true))<form method="POST" action="{{ route('purchasing.orders.receive',$purchase) }}" class="d-grid gap-2">@csrf @foreach($purchase->lines as $line)@if((float)$line->received_quantity < (float)$line->quantity)<div class="border rounded p-2"><div class="small fw-semibold mb-1">{{ $line->variant?->sku }} · sisa {{ number_format((float)$line->quantity - (float)$line->received_quantity,3,',','.') }}</div><div class="row g-1"><div class="col-4"><input name="lines[{{ $line->product_variant_id }}]" type="number" min="0.001" max="{{ (float)$line->quantity-(float)$line->received_quantity }}" step="0.001" class="form-control form-control-sm" aria-label="Jumlah terima {{ $line->variant?->sku }}" placeholder="Jumlah"></div><div class="col-8"><select name="batch_ids[{{ $line->product_variant_id }}]" class="form-select form-select-sm" aria-label="Batch tersedia {{ $line->variant?->sku }}"><option value="">Batch baru / tidak dilacak</option>@foreach($batches->where('warehouse_id',$purchase->warehouse_id)->where('product_variant_id',$line->product_variant_id) as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }}{{ $batch->expires_at ? ' · exp '.$batch->expires_at->format('d M Y') : '' }}</option>@endforeach</select></div><div class="col-6"><input name="batch_numbers[{{ $line->product_variant_id }}]" class="form-control form-control-sm" maxlength="128" placeholder="Nomor lot baru (opsional)"></div><div class="col-3"><input name="manufactured_at[{{ $line->product_variant_id }}]" type="date" class="form-control form-control-sm" aria-label="Tanggal produksi"></div><div class="col-3"><input name="expires_at[{{ $line->product_variant_id }}]" type="date" class="form-control form-control-sm" aria-label="Tanggal kedaluwarsa"></div></div></div>@endif @endforeach<button class="btn btn-sm btn-success">Posting receipt</button></form>
            @elseif($purchase->status==='pending_approval')<a href="{{ route('approvals.index') }}" class="btn btn-sm btn-outline-warning">Buka approval</a>@else<span class="text-secondary small">{{ $purchase->goodsReceipts->count() }} receipt</span>@endif
        </td></tr>@empty<tr><td colspan="5" class="text-center text-secondary py-5">Belum ada purchase order. Buat PO pertama dari formulir di samping.</td></tr>@endforelse
    </tbody></table></div></div></div>

    <div class="col-12 col-xl-5"><div class="card h-100"><div class="card-header"><h2 class="card-title">Catat invoice pemasok</h2></div><div class="card-body"><form method="POST" action="{{ route('purchasing.invoices.store') }}" class="row g-3">@csrf
        <div class="col-12"><label class="form-label">Purchase order</label><select name="purchase_id" class="form-select" required><option value="">Pilih PO</option>@foreach($purchases as $purchase)<option value="{{ $purchase->id }}" data-supplier="{{ $purchase->contact_id }}">#{{ $purchase->id }} · {{ $purchase->contact?->name }}</option>@endforeach</select></div>
        <div class="col-12"><label class="form-label">Pemasok</label><select name="supplier_id" class="form-select" required>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
        <div class="col-12 col-md-6"><label class="form-label">Nomor invoice</label><input name="invoice_number" class="form-control" required></div>
        <div class="col-6 col-md-3"><label class="form-label">Tanggal</label><input name="invoice_date" type="date" value="{{ now()->toDateString() }}" class="form-control" required></div>
        <div class="col-6 col-md-3"><label class="form-label">Jatuh tempo</label><input name="due_date" type="date" class="form-control"></div>
        @foreach(['subtotal'=>'Subtotal','discount'=>'Diskon','tax'=>'Pajak','shipping'=>'Pengiriman'] as $name=>$label)<div class="col-6"><label class="form-label">{{ $label }}</label><input name="{{ $name }}" type="number" min="0" step="0.01" value="0" class="form-control" {{ $name==='subtotal'?'required':'' }}></div>@endforeach
        <div class="col-12"><button class="btn btn-primary w-100">Simpan invoice</button></div>
    </form></div></div></div>

    <div class="col-12 col-xl-7"><div class="card h-100"><div class="card-header"><h2 class="card-title">Utang & pembayaran pemasok</h2></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Invoice</th><th>Total</th><th>Saldo</th><th>Status</th><th>Bayar</th></tr></thead><tbody>
        @forelse($invoices as $invoice)<tr><td><strong>{{ $invoice->invoice_number }}</strong><div class="small text-secondary">{{ $invoice->supplier?->name }} · {{ $invoice->invoice_date->format('d M Y') }}</div></td><td>Rp {{ number_format((float)$invoice->total,0,',','.') }}</td><td class="fw-bold">Rp {{ number_format((float)$invoice->balance,0,',','.') }}</td><td><span class="badge">{{ $invoice->status }}</span></td><td>@if((float)$invoice->balance>0)<form method="POST" action="{{ route('purchasing.payments.store',$invoice) }}" class="d-flex flex-wrap gap-1">@csrf<input name="amount" type="number" min="0.01" max="{{ $invoice->balance }}" step="0.01" class="form-control form-control-sm" style="width:8rem" required aria-label="Jumlah pembayaran"><select name="method" class="form-select form-select-sm" style="width:8rem"><option value="bank_transfer">Transfer</option><option value="cash">Tunai</option><option value="other">Lainnya</option></select><button class="btn btn-sm btn-success">Bayar</button></form>@else<span class="text-success small fw-semibold">Lunas</span>@endif</td></tr>
        @empty<tr><td colspan="5" class="text-center text-secondary py-5">Belum ada invoice pemasok.</td></tr>@endforelse
    </tbody></table></div></div></div>

    <div class="col-12"><div class="card"><div class="card-header"><h2 class="card-title">Purchase return</h2></div><div class="card-body"><p class="text-secondary">Jumlah return divalidasi terhadap barang yang telah diterima dikurangi seluruh return sebelumnya.</p><div class="row g-4">
        <div class="col-12 col-xl-5"><form method="POST" id="purchase-return-form" class="row g-3">@csrf
            <div class="col-12"><label class="form-label">PO</label><select id="return-purchase" class="form-select" required><option value="">Pilih PO</option>@foreach($purchases->whereIn('status',['partial','received']) as $purchase)<option value="{{ $purchase->id }}">#{{ $purchase->id }} · {{ $purchase->contact?->name }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label">Baris PO</label><select name="purchase_line_id" class="form-select" required>@foreach($purchases as $purchase)@foreach($purchase->lines as $line)<option value="{{ $line->id }}">PO #{{ $purchase->id }} · {{ $line->variant?->sku }} · diterima {{ number_format((float)$line->received_quantity,3) }}</option>@endforeach @endforeach</select></div>
            <div class="col-6"><label class="form-label">Jumlah</label><input name="quantity" type="number" min="0.001" step="0.001" class="form-control" required></div><div class="col-6"><label class="form-label">Penyelesaian</label><select name="settlement_type" class="form-select"><option value="supplier_credit">Kredit pemasok</option><option value="cash_refund">Refund tunai</option><option value="replacement">Penggantian</option></select></div>
            <div class="col-12"><label class="form-label">Alasan</label><textarea name="reason" class="form-control" rows="2" required></textarea></div><div class="col-12"><button class="btn btn-danger w-100">Posting return</button></div>
        </form></div>
        <div class="col-12 col-xl-7"><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Return</th><th>PO</th><th>Nilai</th><th>Penyelesaian</th><th>Alasan</th></tr></thead><tbody>@forelse($returns as $return)<tr><td>{{ $return->return_no }}</td><td>#{{ $return->purchase_id }} · {{ $return->purchase?->contact?->name }}</td><td>Rp {{ number_format((float)$return->total,0,',','.') }}</td><td>{{ str_replace('_',' ',$return->settlement_type) }}</td><td>{{ $return->reason }}</td></tr>@empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada purchase return.</td></tr>@endforelse</tbody></table></div></div>
    </div></div></div></div>
</div>
@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>{const select=document.getElementById('return-purchase');const form=document.getElementById('purchase-return-form');select?.addEventListener('change',()=>{form.action=select.value?`{{ url('/purchasing/orders') }}/${select.value}/returns`:'';});});</script>@endpush
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const locations = @json($locations->map(fn ($location) => [
        'id' => $location->id,
        'warehouse_id' => $location->warehouse_id,
        'code' => $location->code,
    ])->values());
    const purchaseWarehouses = @json($purchases->mapWithKeys(fn ($purchase) => [$purchase->id => $purchase->warehouse_id]));

    document.querySelectorAll('form[action*="/receive"]').forEach((form) => {
        const match = form.action.match(/\/purchasing\/orders\/(\d+)\/receive$/);
        const warehouseId = match ? purchaseWarehouses[match[1]] : null;
        const select = document.createElement('select');
        select.name = 'warehouse_location_id';
        select.className = 'form-select form-select-sm';
        select.setAttribute('aria-label', 'Rack atau bin penerimaan');
        select.innerHTML = '<option value="">Tanpa rack/bin spesifik</option>';
        locations.filter((location) => String(location.warehouse_id) === String(warehouseId)).forEach((location) => {
            const option = new Option(location.code, location.id);
            select.add(option);
        });
        form.insertBefore(select, form.firstChild.nextSibling);
        // The server is authoritative: it rejects a location outside the PO warehouse.
    });
});
</script>
@endpush
