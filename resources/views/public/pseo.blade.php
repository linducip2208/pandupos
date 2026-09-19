@extends('layouts.public')
@section('title', $title.' — PanduPOS')
@section('description', 'Panduan objektif '.$title.' untuk membantu bisnis Indonesia memilih sistem kasir dan inventory yang sesuai kebutuhan.')
@push('head')
@php
$items = $products->values()->map(fn($product, $index) => ['@type'=>'ListItem','position'=>$index + 1,'name'=>$product->name])->all();
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'ItemList', 'name' => $title, 'itemListElement' => $items],
        [
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Bagaimana memilih sistem POS yang tepat?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Mulai dari alur transaksi, akurasi stok, kontrol akses, laporan, biaya total, dan kemampuan integrasi.']],
                ['@type' => 'Question', 'name' => 'Apakah PanduPOS cocok untuk multi-cabang?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Ya. PanduPOS memisahkan tenant, cabang, gudang, register, serta hak akses pengguna.']],
            ],
        ],
    ],
];
@endphp
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) !!}</script>
@endpush
@section('content')
<section class="page-hero"><div class="public-container"><span class="eyebrow">PANDUAN PEMILIHAN 2026</span><h1>{{ $title }}</h1><p>Perbandingan berbasis kebutuhan operasional, bukan sekadar daftar fitur. Gunakan panduan ini untuk menilai transaksi, persediaan, kontrol tim, biaya, dan kesiapan pertumbuhan.</p></div></section>
<section class="section"><div class="public-container content-grid"><article class="content-card article-body">
<h2>Ringkasan keputusan</h2>
<p>Memilih solusi untuk <strong>{{ Str::headline($primary) }}</strong> sebaiknya dimulai dari masalah yang benar-benar terjadi setiap hari. Banyak bisnis mengejar daftar fitur panjang, tetapi melewatkan kualitas data, kemudahan kasir, dan kemampuan menelusuri perubahan stok. Sistem yang baik harus membuat transaksi lebih cepat sekaligus memberi owner angka yang dapat dipercaya. Ia juga perlu menjaga data antar cabang tetap terpisah, mengurangi input berulang, dan menyediakan jejak audit ketika ada koreksi.</p>
@if($type === 'compare')<h2>{{ Str::headline($primary) }} dibanding {{ Str::headline($secondary) }}</h2><p>Keduanya perlu dinilai dengan skenario yang sama: jam sibuk di kasir, penerimaan barang parsial, retur pelanggan, perpindahan gudang, serta rekonsiliasi akhir hari. Pilihan pertama dapat unggul untuk alur sederhana, sedangkan pilihan kedua mungkin lebih cocok ketika organisasi membutuhkan role, approval, dan integrasi. Jangan mengambil keputusan hanya dari tampilan demo; uji data nyata, jumlah SKU, jumlah outlet, dan kualitas dukungan setelah implementasi.</p>@else<h2>Kriteria yang paling penting</h2><p>Prioritaskan kecepatan checkout, proteksi oversell, ledger stok yang tidak mudah diubah, penerimaan pembelian parsial, dan laporan laba berbasis biaya barang aktual. Untuk usaha yang berkembang, periksa pula API, mode offline, idempotency pembayaran, serta pengaturan entitlement. Kriteria ini mencegah migrasi ulang ketika bisnis membuka cabang baru atau menambah kanal penjualan.</p>@endif
<h2>Evaluasi biaya dan risiko</h2><p>Harga langganan hanya satu bagian dari biaya total. Hitung waktu pelatihan, proses migrasi, kebutuhan perangkat, ketergantungan kepada vendor, dan biaya ketika sistem berhenti. Minta skenario pemulihan data, mekanisme backup, dan dokumentasi hak akses. Solusi yang sedikit lebih mahal bisa lebih ekonomis bila mengurangi selisih stok, transaksi ganda, dan pekerjaan rekap. Sebaliknya, paket besar tidak bernilai bila sebagian besar modul tidak dipakai oleh tim.</p>
<h2>Rekomendasi implementasi</h2><p>Mulailah dengan satu cabang percontohan dan data produk yang sudah dibersihkan. Tetapkan pemilik proses untuk master data, pembelian, gudang, dan kasir. Jalankan satu siklus lengkap dari purchase order sampai penjualan, retur, dan laporan. Setelah angka cocok dengan kontrol manual, baru perluas ke cabang lain. Pendekatan bertahap memberi ruang bagi tim untuk belajar tanpa menghentikan operasi harian.</p>
<h2>Mengapa PanduPOS relevan</h2><p>PanduPOS dibangun sebagai modular monolith multi-tenant. Checkout berjalan secara atomic, stok dicatat sebagai ledger, penerimaan pembelian dapat dilakukan parsial, dan pembayaran memiliki perlindungan idempotency. Platform juga menyediakan plan, entitlement, usage limit, API v1, webhook bertanda tangan, serta sinkronisasi perangkat. Arsitektur ini cocok untuk pemilik bisnis yang ingin memulai dari POS dan inventory, lalu bertumbuh tanpa mengganti fondasi sistem.</p>
<h2>Pertanyaan umum</h2><h3>Bagaimana melakukan uji coba?</h3><p>Gunakan akun demo, ikuti tutorial di dokumentasi, dan jalankan transaksi dengan produk contoh. Catat waktu checkout serta kesesuaian perubahan stok.</p><h3>Apakah data cabang aman?</h3><p>Data tenant dilindungi melalui global scope, middleware, policy, dan test isolasi. Hak akses tetap perlu dikonfigurasi sesuai tanggung jawab tiap pengguna.</p>
<div class="source-cta"><h2>Butuh source code yang bisa dikembangkan?</h2><p>PanduPOS menyediakan fondasi Laravel modular untuk produk POS whitelabel. Kanal penjualan dan kontak dikendalikan operator deployment melalui konfigurasi.</p><div class="hero-actions">@if(config('marketing.whatsapp_url'))<a class="button button-light" target="_blank" rel="noopener noreferrer" href="{{ config('marketing.whatsapp_url') }}">Hubungi WhatsApp</a>@endif @if(config('marketing.source_code_url'))<a class="button button-outline-light" target="_blank" rel="noopener noreferrer" href="{{ config('marketing.source_code_url') }}">Lihat Source Code</a>@else<a class="button button-light" href="{{ route('docs') }}">Baca Dokumentasi</a>@endif</div></div>
</article><aside class="sidebar-stack"><div class="content-card"><h3>Checklist evaluasi</h3><ul><li>Kecepatan kasir</li><li>Akurasi ledger stok</li><li>Multi-cabang</li><li>Role dan audit</li><li>API dan offline sync</li><li>Biaya total</li></ul></div><div class="content-card"><h3>Topik terkait</h3>@foreach($categories as $category)<p><a href="{{ route('pseo.best', Str::slug($category)) }}">Terbaik untuk {{ $category }}</a></p>@endforeach</div></aside></div></section>
@endsection
