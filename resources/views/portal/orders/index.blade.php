@extends('portal.layout')
@section('title', 'Pesanan Saya')
@section('content')
<div class="portal-heading"><div><span class="eyebrow">SELF-SERVICE</span><h1>Pesanan saya</h1></div></div>
<section class="portal-panel"><div class="portal-table-wrap"><table><thead><tr><th>Nomor</th><th>Tanggal</th><th>Status</th><th>Fulfillment</th><th>Total</th><th></th></tr></thead><tbody>
@forelse($orders as $invoice)<tr><td>{{ $invoice->invoice_no ?: '#'.$invoice->id }}</td><td>{{ $invoice->created_at->format('d M Y') }}</td><td>{{ $invoice->status }}</td><td>{{ $invoice->fulfillment_status ?: 'diproses' }}</td><td>Rp {{ number_format($invoice->total,0,',','.') }}</td><td><a href="{{ route('portal.orders.show',$invoice->id) }}">Lihat</a></td></tr>@empty<tr><td colspan="6">Belum ada pesanan.</td></tr>@endforelse
</tbody></table></div>{{ $orders->links() }}</section>
@endsection
