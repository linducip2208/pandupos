<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! env('SEED_DEMO_IN_PROD', false)) {
            return;
        }

        $author = User::where('email', 'owner@demo.local')->first() ?? User::first();
        if (! $author) {
            return;
        }

        $topics = [
            'POS' => ['Cara Memilih Sistem POS untuk Toko Multi-Cabang', 'Checklist Tutup Kasir yang Mengurangi Selisih', 'Mengapa Transaksi Harus Idempotent'],
            'Inventory' => ['Panduan Stok Minimum untuk Bisnis Ritel', 'Memahami Weighted Average Cost dengan Sederhana', 'Cara Aman Memindahkan Stok Antar Gudang'],
            'Pembelian' => ['Penerimaan Barang Parsial Tanpa Mengacaukan Stok', 'Purchase Order yang Mudah Diaudit'],
            'Strategi Bisnis' => ['Laporan Harian yang Benar-Benar Dibutuhkan Owner', 'Persiapan Membuka Cabang Kedua dengan Data yang Rapi'],
        ];

        foreach ($topics as $categoryName => $titles) {
            $category = BlogCategory::updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'description' => "Panduan praktis {$categoryName} untuk bisnis Indonesia."]
            );

            foreach ($titles as $offset => $title) {
                BlogPost::updateOrCreate(['slug' => Str::slug($title)], [
                    'category_id' => $category->id,
                    'author_id' => $author->id,
                    'title' => $title,
                    'excerpt' => "Langkah praktis {$title} agar operasional lebih akurat, cepat, dan mudah diaudit.",
                    'content' => $this->content($title),
                    'is_published' => true,
                    'published_at' => now()->subDays(($category->id * 3) + $offset),
                    'meta_title' => $title.' — PanduPOS',
                    'meta_description' => "Pelajari {$title} melalui panduan operasional yang praktis untuk bisnis Indonesia.",
                ]);
            }
        }
    }

    private function content(string $title): string
    {
        return "{$title}\n\nOperasional yang rapi dimulai dari definisi proses yang sama untuk seluruh tim. Tuliskan siapa yang boleh membuat data, siapa yang memeriksa, dan kapan transaksi dianggap selesai. Langkah sederhana ini mencegah koreksi berulang serta membuat laporan lebih mudah dipercaya.\n\nMulailah dari data contoh yang mewakili kondisi nyata: produk dengan beberapa varian, pembelian yang diterima sebagian, pembayaran campuran, dan satu transaksi retur. Jalankan alur tersebut dari awal sampai laporan. Bila angka stok dan nilai transaksi tetap konsisten, proses siap diperluas ke tim yang lebih besar.\n\nPanduPOS mencatat transaksi secara atomic dan memakai ledger untuk perubahan persediaan. Artinya, koreksi dilakukan melalui transaksi yang dapat ditelusuri, bukan dengan mengubah angka akhir tanpa riwayat. Hak akses, request ID, dan audit log membantu tim menemukan sumber perubahan ketika ada perbedaan.\n\nEvaluasi hasil setiap minggu. Periksa waktu pelayanan, jumlah koreksi, selisih stok, produk yang sering habis, serta invoice yang belum selesai. Gunakan temuan itu untuk memperbaiki SOP, pelatihan, dan konfigurasi sistem. Teknologi memberi dampak terbaik ketika proses dan tanggung jawab manusianya juga jelas.";
    }
}
