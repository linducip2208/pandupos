@extends('portal.layout')
@section('title', 'Dashboard Pelanggan')
@section('content')
<div class="portal-heading"><div><span class="eyebrow">RINGKASAN AKUN</span><h1>Halo, {{ $customer->contact->name }}</h1></div><a class="portal-link-button" href="{{ route('portal.orders.index') }}">Lihat semua pesanan</a></div>
<div class="portal-stats">
    <article><small>Pesanan aktif</small><strong>{{ number_format($activeOrders) }}</strong></article>
    <article><small>Total transaksi</small><strong>Rp {{ number_format($totalSpent, 0, ',', '.') }}</strong></article>
    <article><small>Perlu dibayar</small><strong>Rp {{ number_format($outstanding, 0, ',', '.') }}</strong></article>
</div>
<section class="portal-panel"><div class="portal-panel-head"><h2>Transaksi terbaru</h2><a href="{{ route('portal.invoices.index') }}">Semua invoice</a></div>
    <div class="portal-table-wrap"><table><thead><tr><th>Invoice</th><th>Status</th><th>Pembayaran</th><th>Total</th><th></th></tr></thead><tbody>
    @forelse($recentInvoices as $invoice)<tr><td>{{ $invoice->invoice_no ?: '#'.$invoice->id }}</td><td><span class="portal-badge">{{ $invoice->status }}</span></td><td>{{ $invoice->payment_status }}</td><td>Rp {{ number_format($invoice->total, 0, ',', '.') }}</td><td><a href="{{ route('portal.invoices.show', $invoice->id) }}">Detail</a></td></tr>@empty<tr><td colspan="5">Belum ada transaksi.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
