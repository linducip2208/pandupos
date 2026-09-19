@extends('layouts.tabler')

@section('title', 'Sales Return & Refund')
@section('page-title', 'Return, Void & Refund Penjualan')
@section('page-subtitle', 'Pengembalian stok, pembatalan void, dan reversal pembayaran yang diaudit')

@section('content')
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
    <div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h2 class="card-title">Buat return penjualan</h2></div><div class="card-body">
        <p class="text-secondary">Return mengembalikan stok dengan biaya asal (WAC), divalidasi terhadap jumlah terjual dikurangi return sebelumnya. Refund terpisah membalikkan pembayaran.</p>
        <form method="POST" id="sales-return-form" class="row g-3">@csrf
            <div class="col-12"><label class="form-label">Invoice final</label><select id="return-invoice" class="form-select" required><option value="">Pilih invoice</option>@foreach($invoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->invoice_no }} · Rp {{ number_format((float)$invoice->total,0,',','.') }} · {{ $invoice->contact?->name ?? 'Walk-in' }}</option>@endforeach</select></div>
            <div class="col-8"><label class="form-label">Varian (ID)</label><input name="lines[0][variant_id]" type="number" class="form-control" required></div>
            <div class="col-4"><label class="form-label">Jumlah</label><input name="lines[0][quantity]" type="number" min="0.001" step="0.001" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Alasan wajib</label><textarea name="reason" class="form-control" rows="2" required></textarea></div>
            <div class="col-12"><button class="btn btn-warning w-100">Catat return & kembalikan stok</button></div>
        </form>
        <hr>
        <form method="POST" id="sales-void-form" class="row g-3">@csrf
            <div class="col-12"><label class="form-label">Void invoice (butuh izin pos.sale.void)</label><select id="void-invoice" class="form-select"><option value="">Pilih invoice</option>@foreach($invoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->invoice_no }}</option>@endforeach</select></div>
            <div class="col-12"><input name="reason" class="form-control" placeholder="Alasan void wajib" required></div>
            <div class="col-12"><button class="btn btn-outline-danger w-100">Void & kembalikan sisa stok</button></div>
        </form>
    </div></div></div>
    <div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h2 class="card-title">Return & refund tercatat</h2></div><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Return</th><th>Invoice</th><th>Nilai</th><th>Refund</th><th>Aksi</th></tr></thead><tbody>
        @forelse($returns as $return)<tr><td><strong>#{{ $return->id }}</strong><div class="small text-secondary">{{ $return->reason }}</div></td><td>{{ $return->invoice?->invoice_no }}</td><td>Rp {{ number_format((float)$return->total,0,',','.') }}</td><td>Rp {{ number_format((float)$return->refunds->sum('amount'),0,',','.') }}</td>
        <td><form method="POST" action="{{ route('sales-returns.refunds.store', $return) }}" class="d-flex flex-wrap gap-1">@csrf<input name="amount" type="number" min="0.01" step="0.01" class="form-control form-control-sm" style="width:7rem" placeholder="Nominal" required><select name="method" class="form-select form-select-sm" style="width:7rem"><option value="cash">Tunai</option><option value="transfer">Transfer</option><option value="qris">QRIS</option><option value="ewallet">E-wallet</option><option value="card">Kartu</option></select><input name="reason" class="form-control form-control-sm" placeholder="Alasan refund" required><button class="btn btn-sm btn-danger">Refund</button></form></td></tr>
        @empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada return penjualan.</td></tr>@endforelse
    </tbody></table></div></div></div>
</div>
@push('scripts')<script>document.addEventListener('DOMContentLoaded',()=>{const inv=document.getElementById('return-invoice');const form=document.getElementById('sales-return-form');inv?.addEventListener('change',()=>{form.action=inv.value?`{{ url('/sales-returns/invoices') }}/${inv.value}/returns`:'';});const vinv=document.getElementById('void-invoice');const vform=document.getElementById('sales-void-form');vinv?.addEventListener('change',()=>{vform.action=vinv.value?`{{ url('/sales-returns/invoices') }}/${vinv.value}/void`:'';});});</script>@endpush
@endsection
