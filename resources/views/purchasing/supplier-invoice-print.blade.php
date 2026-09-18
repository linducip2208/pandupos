<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice Pemasok {{ $invoice->invoice_number }}</title>
    <style>
        body { color: #1f2937; font: 14px/1.5 Arial, sans-serif; margin: 36px auto; max-width: 760px; }
        header, .totals { display: flex; justify-content: space-between; gap: 24px; }
        h1 { font-size: 24px; margin: 0 0 4px; } h2 { font-size: 15px; margin-top: 28px; }
        table { border-collapse: collapse; width: 100%; } th, td { border-bottom: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { color: #4b5563; font-size: 11px; text-transform: uppercase; } .right { text-align: right; }
        .muted { color: #6b7280; } .totals { justify-content: flex-end; margin-top: 20px; } .totals table { max-width: 300px; }
        @media print { body { margin: 12px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()">Cetak</button>
    <header>
        <div><h1>Invoice Pemasok</h1><div class="muted">{{ config('app.name') }}</div></div>
        <div class="right"><strong>{{ $invoice->invoice_number }}</strong><br><span class="muted">{{ $invoice->invoice_date->format('d M Y') }}</span></div>
    </header>
    <h2>Pemasok</h2>
    <div>{{ $invoice->supplier?->name }}<br><span class="muted">PO #{{ $invoice->purchase_id ?? '—' }} · Jatuh tempo {{ $invoice->due_date?->format('d M Y') ?? '—' }}</span></div>
    <h2>Ringkasan</h2>
    <table><tbody>
        <tr><td>Subtotal</td><td class="right">Rp {{ number_format((float) $invoice->subtotal, 2, ',', '.') }}</td></tr>
        <tr><td>Diskon</td><td class="right">- Rp {{ number_format((float) $invoice->discount, 2, ',', '.') }}</td></tr>
        <tr><td>Pajak dan pengiriman</td><td class="right">Rp {{ number_format((float) $invoice->tax + (float) $invoice->shipping, 2, ',', '.') }}</td></tr>
    </tbody></table>
    <div class="totals"><table><tbody>
        <tr><th>Total</th><th class="right">Rp {{ number_format((float) $invoice->total, 2, ',', '.') }}</th></tr>
        <tr><td>Terbayar</td><td class="right">Rp {{ number_format((float) $invoice->paid, 2, ',', '.') }}</td></tr>
        <tr><th>Sisa</th><th class="right">Rp {{ number_format((float) $invoice->balance, 2, ',', '.') }}</th></tr>
    </tbody></table></div>
    <h2>Riwayat pembayaran</h2>
    <table><thead><tr><th>Tanggal</th><th>Metode / Referensi</th><th class="right">Nilai</th></tr></thead><tbody>
        @forelse($invoice->payments as $payment)<tr><td>{{ $payment->paid_at->format('d M Y H:i') }}</td><td>{{ $payment->method }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</td><td class="right">Rp {{ number_format((float) $payment->amount, 2, ',', '.') }}</td></tr>
        @empty<tr><td colspan="3" class="muted">Belum ada pembayaran.</td></tr>@endforelse
    </tbody></table>
</body>
</html>
