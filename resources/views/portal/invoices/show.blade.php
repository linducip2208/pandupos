@extends('portal.layout')
@section('title', 'Detail Invoice')
@section('content')
<div class="portal-heading"><div><span class="eyebrow">INVOICE</span><h1>{{ $invoice->invoice_no ?: '#'.$invoice->id }}</h1></div><div class="portal-actions"><a class="portal-link-button" href="{{ route('portal.invoices.pdf',$invoice->id) }}">Unduh PDF</a><a class="portal-link-button" href="{{ route('portal.invoices.index') }}">Kembali</a></div></div>
@include('portal.partials.invoice-detail', ['showPayments' => true])
<section class="portal-panel portal-upload"><h2>Kirim bukti pembayaran</h2><p>File JPG, PNG, atau PDF maksimal 5 MB. Tim toko akan memverifikasi kiriman Anda.</p>
@if($errors->any())<div class="portal-error">{{ $errors->first() }}</div>@endif
<form method="POST" enctype="multipart/form-data" action="{{ route('portal.payment-proofs.store',$invoice->id) }}">@csrf<label>Bukti pembayaran<input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required></label><label>Catatan<textarea name="notes" rows="3" placeholder="Nomor referensi atau keterangan tambahan"></textarea></label><button class="portal-primary" type="submit">Kirim bukti</button></form>
</section>
@endsection
