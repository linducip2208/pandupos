<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Order #{{ $purchase->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; margin: 32px; }
        header { display:flex; justify-content:space-between; border-bottom:2px solid #4f46e5; padding-bottom:16px; }
        table { width:100%; border-collapse:collapse; margin-top:24px; }
        th, td { border-bottom:1px solid #d1d5db; padding:9px; text-align:left; }
        th { background:#eef2ff; font-size:12px; text-transform:uppercase; }
        .right { text-align:right; } .muted { color:#6b7280; } .total { font-size:18px; font-weight:bold; }
        @media print { body { margin: 12mm; } .no-print { display:none; } }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">Cetak</button></p>
    <header>
        <div><h1>Purchase Order</h1><p class="muted">PO #{{ $purchase->id }} · {{ str_replace('_', ' ', $purchase->status) }}</p></div>
        <div class="right"><strong>{{ $purchase->warehouse?->branch?->name ?? config('app.name') }}</strong><br>{{ $purchase->warehouse?->name }}</div>
    </header>
    <p><strong>Pemasok:</strong> {{ $purchase->contact?->name }}<br><strong>Dibuat:</strong> {{ $purchase->created_at?->format('d M Y H:i') }}</p>
    <table><thead><tr><th>SKU</th><th>Produk</th><th class="right">Jumlah</th><th class="right">Harga</th><th class="right">Total</th></tr></thead><tbody>
        @foreach($purchase->lines as $line)<tr><td>{{ $line->variant?->sku }}</td><td>{{ $line->variant?->product?->name }}</td><td class="right">{{ number_format((float) $line->quantity, 3, ',', '.') }} {{ $line->variant?->product?->unit?->short_name }}</td><td class="right">Rp {{ number_format((float) $line->unit_cost, 2, ',', '.') }}</td><td class="right">Rp {{ number_format((float) $line->quantity * (float) $line->unit_cost, 2, ',', '.') }}</td></tr>@endforeach
    </tbody></table>
    <p class="right total">Total: Rp {{ number_format((float) $purchase->total, 2, ',', '.') }}</p>
</body>
</html>
