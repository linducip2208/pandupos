@extends('layouts.tabler')

@section('title', $purchase ? 'Edit draft purchase order' : 'Purchase order multi-baris')

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col"><h2 class="page-title">{{ $purchase ? 'Edit draft PO #'.$purchase->id : 'Buat purchase order multi-baris' }}</h2>
                <div class="text-secondary">Draft tidak memengaruhi stok. Stok hanya bertambah saat goods receipt diposting.</div></div>
            <div class="col-auto"><a href="{{ route('purchasing.index') }}" class="btn btn-outline-secondary">Kembali ke purchasing</a></div>
        </div>
    </div>
</div>

<div class="container-xl">
    <form method="POST" action="{{ $purchase ? route('purchasing.orders.update', $purchase) : route('purchasing.orders.store') }}" class="card" id="purchase-order-form">
        @csrf
        @if($purchase) @method('PUT') @endif
        <div class="card-body">
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6"><label class="form-label">Pemasok</label><select name="contact_id" class="form-select" required><option value="">Pilih pemasok</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('contact_id', $purchase?->contact_id) == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
                <div class="col-12 col-md-6"><label class="form-label">Gudang tujuan</label><select name="warehouse_id" class="form-select" required><option value="">Pilih gudang</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $purchase?->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2"><h3 class="card-title mb-0">Baris purchase order</h3><button type="button" class="btn btn-outline-primary btn-sm" id="add-po-line">Tambah baris</button></div>
            <div class="table-responsive"><table class="table table-vcenter" id="po-lines"><thead><tr><th>Produk / varian</th><th style="width: 9rem">Jumlah</th><th style="width: 12rem">Satuan beli</th><th style="width: 11rem">Harga satuan</th><th style="width: 3rem"></th></tr></thead><tbody>
                @php($existingLines = old('lines', $purchase?->lines?->map(fn ($line) => ['product_variant_id' => $line->product_variant_id, 'quantity' => $line->quantity, 'unit_id' => $line->variant?->product?->unit_id, 'unit_cost' => $line->unit_cost])->all() ?: [['product_variant_id' => '', 'quantity' => '', 'unit_id' => '', 'unit_cost' => '']]))
                @foreach($existingLines as $index => $line)
                <tr class="po-line">
                    <td><select name="lines[{{ $index }}][product_variant_id]" class="form-select" required><option value="">Pilih produk</option>@foreach($variants as $variant)<option value="{{ $variant->id }}" @selected(($line['product_variant_id'] ?? null) == $variant->id)>{{ $variant->sku }} - {{ $variant->product?->name }}</option>@endforeach</select></td>
                    <td><input name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" type="number" min="0.001" step="0.001" class="form-control" required></td>
                    <td><select name="lines[{{ $index }}][unit_id]" class="form-select" required><option value="">Satuan</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(($line['unit_id'] ?? null) == $unit->id)>{{ $unit->name }} ({{ $unit->short_name }})</option>@endforeach</select></td>
                    <td><input name="lines[{{ $index }}][unit_cost]" value="{{ $line['unit_cost'] ?? '' }}" type="number" min="0" step="0.01" class="form-control" required></td>
                    <td><button type="button" class="btn btn-icon btn-outline-danger remove-po-line" aria-label="Hapus baris">×</button></td>
                </tr>
                @endforeach
            </tbody></table></div>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-end gap-2">
            @if($purchase)
                <button class="btn btn-primary">Simpan perubahan draft</button>
            @else
                <button name="save_as" value="draft" class="btn btn-outline-primary">Simpan sebagai draft</button>
                <button name="save_as" value="submit" class="btn btn-primary">Buat dan kirim PO</button>
            @endif
        </div>
    </form>
</div>

<template id="po-line-template"><tr class="po-line"><td><select name="lines[__INDEX__][product_variant_id]" class="form-select" required><option value="">Pilih produk</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }} - {{ $variant->product?->name }}</option>@endforeach</select></td><td><input name="lines[__INDEX__][quantity]" type="number" min="0.001" step="0.001" class="form-control" required></td><td><select name="lines[__INDEX__][unit_id]" class="form-select" required><option value="">Satuan</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->short_name }})</option>@endforeach</select></td><td><input name="lines[__INDEX__][unit_cost]" type="number" min="0" step="0.01" class="form-control" required></td><td><button type="button" class="btn btn-icon btn-outline-danger remove-po-line" aria-label="Hapus baris">×</button></td></tr></template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.querySelector('#po-lines tbody');
    const template = document.querySelector('#po-line-template').innerHTML;
    document.querySelector('#add-po-line').addEventListener('click', () => {
        body.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', String(body.querySelectorAll('.po-line').length)));
    });
    body.addEventListener('click', (event) => {
        if (!event.target.closest('.remove-po-line')) return;
        if (body.querySelectorAll('.po-line').length === 1) return;
        event.target.closest('.po-line').remove();
    });
});
</script>
@endpush
