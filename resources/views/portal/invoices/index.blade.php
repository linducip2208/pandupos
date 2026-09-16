@extends('portal.layout')
@section('title', 'Invoice Saya')
@section('content')
<div class="portal-heading"><div><span class="eyebrow">DOKUMEN TRANSAKSI</span><h1>Invoice saya</h1></div></div>
<section class="portal-panel"><div class="portal-table-wrap"><table><thead><tr><th>Nomor</th><th>Tanggal</th><th>Pembayaran</th><th>Total</th><th></th></tr></thead><tbody>
@forelse($invoices as $invoice)<tr><td>{{ $invoice->invoice_no ?: '#'.$invoice->id }}</td><td>{{ $invoice->created_at->format('d M Y') }}</td><td><span class="portal-badge">{{ $invoice->payment_status }}</span></td><td>Rp {{ number_format($invoice->total,0,',','.') }}</td><td><a href="{{ route('portal.invoices.show',$invoice->id) }}">Detail</a></td></tr>@empty<tr><td colspan="5">Belum ada invoice.</td></tr>@endforelse
</tbody></table></div>{{ $invoices->links() }}</section>
@endsection
