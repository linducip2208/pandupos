@extends('layouts.tabler')

@section('title', 'Purchase Return '.$purchaseReturn->return_no)
@section('page-title', 'Purchase Return '.$purchaseReturn->return_no)
@section('page-subtitle', 'PO #'.$purchaseReturn->purchase_id.' · '.$purchaseReturn->purchase?->contact?->name.' · '.$purchaseReturn->status)

@section('content')
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
    <div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h2 class="card-title">Siklus draft → review → approve → posting</h2><span class="badge ms-2">{{ $purchaseReturn->status }}</span></div>
        <div class="card-body">
            <dl class="row">
                <div class="col-6"><dt class="text-secondary small">Pemasok</dt><dd>{{ $purchaseReturn->purchase?->contact?->name }}</dd></div>
                <div class="col-6"><dt class="text-secondary small">Gudang</dt><dd>{{ $purchaseReturn->purchase?->warehouse?->name }}</dd></div>
                <div class="col-4"><dt class="text-secondary small">Subtotal</dt><dd>Rp {{ number_format((float)$purchaseReturn->subtotal,0,',','.') }}</dd></div>
                <div class="col-4"><dt class="text-secondary small">Pajak</dt><dd>Rp {{ number_format((float)$purchaseReturn->tax,0,',','.') }}</dd></div>
                <div class="col-4"><dt class="text-secondary small">Total</dt><dd class="fw-bold">Rp {{ number_format((float)$purchaseReturn->total,0,',','.') }}</dd></div>
                <div class="col-12"><dt class="text-secondary small">Penyelesaian</dt><dd>{{ str_replace('_',' ',$purchaseReturn->settlement_type) }}</dd></div>
                <div class="col-12"><dt class="text-secondary small">Alasan</dt><dd>{{ $purchaseReturn->reason }}</dd></div>
            </dl>
            <div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>Produk</th><th>Jumlah</th><th>Biaya</th><th>Batch</th><th>Rak/bin</th></tr></thead><tbody>
            @foreach($purchaseReturn->lines as $line)<tr><td>{{ $line->variant?->sku }}</td><td>{{ number_format((float)$line->quantity,3) }}</td><td>Rp {{ number_format((float)$line->unit_cost,0,',','.') }}</td><td>{{ $line->batch?->batch_number ?? '—' }}</td><td>{{ $line->warehouseLocation?->code ?? '—' }}</td></tr>@endforeach
            </tbody></table></div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <a href="{{ route('purchasing.index') }}" class="btn btn-outline-secondary">Kembali</a>
            @if($purchaseReturn->status === 'draft')<form method="POST" action="{{ route('purchasing.returns.submit', $purchaseReturn) }}">@csrf<button class="btn btn-primary">Kirim untuk review</button></form>@endif
            @if($purchaseReturn->status === 'reviewed')<form method="POST" action="{{ route('purchasing.returns.approve', $purchaseReturn) }}">@csrf<button class="btn btn-warning">Setujui (approver)</button></form>@endif
            @if($purchaseReturn->status === 'approved')<form method="POST" action="{{ route('purchasing.returns.post', $purchaseReturn) }}">@csrf<button class="btn btn-danger">Posting & kunci stok</button></form>@endif
            @if($purchaseReturn->status === 'posted')<span class="badge bg-green">Immutable — tidak dapat diubah</span>@endif
        </div>
    </div></div>
</div>
@endsection
