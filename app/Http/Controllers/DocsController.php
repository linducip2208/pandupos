<?php

namespace App\Http\Controllers;

class DocsController extends Controller
{
    public function index()
    {
        return view('public.docs', [
            'accounts' => [
                ['role' => 'Owner', 'email' => 'owner@demo.local', 'password' => 'password', 'scope' => 'Seluruh operasional tenant demo'],
                ['role' => 'Manager', 'email' => 'manager@demo.local', 'password' => 'password', 'scope' => 'Dashboard, laporan, dan approval operasional'],
                ['role' => 'Kasir', 'email' => 'cashier@demo.local', 'password' => 'password', 'scope' => 'POS dan ringkasan transaksi kasir'],
                ['role' => 'Gudang', 'email' => 'warehouse@demo.local', 'password' => 'password', 'scope' => 'Stok, gudang, dan mutasi operasional'],
                ['role' => 'Purchasing', 'email' => 'purchasing@demo.local', 'password' => 'password', 'scope' => 'Pembelian dan penerimaan barang'],
                ['role' => 'Sales', 'email' => 'sales@demo.local', 'password' => 'password', 'scope' => 'Penjualan dan invoice pelanggan'],
                ['role' => 'Pelanggan', 'email' => 'customer@demo.local', 'password' => 'password', 'scope' => 'Pesanan, invoice, PDF, dan bukti pembayaran di /portal'],
                ['role' => 'Platform Admin', 'email' => 'Diatur melalui PLATFORM_ADMIN_EMAIL', 'password' => 'Diatur melalui environment', 'scope' => 'Manajemen SaaS lintas tenant'],
            ],
            'phases' => $this->tutorial(),
            'features' => $this->features(),
            'tutorialStepCount' => collect($this->tutorial())->sum(fn (array $phase) => count($phase['steps'])),
        ]);
    }

    private function tutorial(): array
    {
        return [
            ['title' => 'Setup awal', 'steps' => ['Masuk dengan akun owner demo', 'Periksa cabang, gudang, dan register', 'Pilih paket serta entitlement tenant', 'Atur role dan izin tim']],
            ['title' => 'Master data', 'steps' => ['Buat kategori, merek, dan satuan', 'Tambahkan produk dan varian SKU', 'Masukkan pelanggan serta pemasok', 'Tentukan stok minimum tiap produk']],
            ['title' => 'Pembelian & stok', 'steps' => ['Buat purchase order', 'Terima barang sebagian atau penuh', 'Validasi ledger stok gudang', 'Transfer stok antar gudang']],
            ['title' => 'Penjualan harian', 'steps' => ['Buka POS Kasir', 'Pilih produk dan pelanggan', 'Gunakan pembayaran tunai atau split', 'Tahan dan lanjutkan transaksi', 'Cetak struk', 'Proses retur atau void sesuai izin']],
            ['title' => 'Operasional', 'steps' => ['Pantau stok minimum', 'Audit pergerakan stok', 'Periksa status fulfillment', 'Tinjau sinkronisasi perangkat offline']],
            ['title' => 'Keamanan', 'steps' => ['Tinjau audit log', 'Periksa kesehatan modul', 'Validasi signature webhook', 'Gunakan impersonation dengan audit trail']],
            ['title' => 'Finance & SaaS', 'steps' => ['Tinjau invoice billing', 'Kelola subscription', 'Atur kupon dan afiliasi', 'Proses payout komisi']],
            ['title' => 'Laporan', 'steps' => ['Buka ringkasan penjualan', 'Analisis nilai persediaan', 'Periksa laba berbasis COGS', 'Ekspor data API untuk analisis lanjutan']],
        ];
    }

    private function features(): array
    {
        return [
            ['group' => 'Transaksi', 'title' => 'POS kasir cepat', 'screenshot' => 'pos.png', 'url' => '/pos', 'description' => 'Checkout atomic dengan split payment, idempotency, hold/resume, dan proteksi oversell.', 'bullets' => ['Scanner-ready', 'Split payment', 'Hold/resume', 'Struk cetak']],
            ['group' => 'Dashboard', 'title' => 'Ringkasan sesuai role', 'screenshot' => 'dashboard.png', 'url' => '/dashboard', 'description' => 'Owner, kasir, purchasing, dan gudang memperoleh widget sesuai tanggung jawabnya.', 'bullets' => ['Widget per role', 'Auto-refresh', 'Recent sales', 'Low-stock alert']],
            ['group' => 'Laporan', 'title' => 'Laporan bisnis utama', 'screenshot' => 'report-business.png', 'url' => '/reports/bisnis', 'description' => 'Tren omzet dan transaksi dapat difilter serta diekspor tanpa rekap manual.', 'bullets' => ['Date range', 'Group by', 'Chart', 'CSV dan PDF']],
            ['group' => 'Finance', 'title' => 'Laporan keuangan', 'screenshot' => 'report-finance.png', 'url' => '/reports/keuangan', 'description' => 'Pendapatan, COGS, dan laba kotor dihitung dari transaksi aktual.', 'bullets' => ['Pendapatan', 'COGS', 'Laba kotor', 'Detail periode']],
            ['group' => 'Operasional', 'title' => 'Laporan operasional', 'screenshot' => 'report-operations.png', 'url' => '/reports/operasional', 'description' => 'Mutasi masuk-keluar dan valuasi stok tersedia dalam satu halaman responsif.', 'bullets' => ['Mutasi', 'Valuasi', 'Multi-gudang', 'Audit data']],
            ['group' => 'Kontrol', 'title' => 'Approval transaksi besar', 'screenshot' => 'approvals.png', 'url' => '/approvals', 'description' => 'Transaksi di atas threshold menunggu persetujuan tanpa mengurangi stok lebih dulu.', 'bullets' => ['Threshold', 'Approve/reject', 'Alasan', 'Audit trail']],
        ];
    }
}
