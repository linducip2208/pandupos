# Deployment PanduPOS

## 1. Kebutuhan server

- PHP 8.3+ beserta ekstensi Laravel standar
- MySQL 8+ atau PostgreSQL 15+
- Node.js 20+ untuk build aset
- Nginx, Supervisor, dan cron
- Redis direkomendasikan untuk cache serta queue production

## 2. Instalasi

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Isi `APP_URL`, koneksi database, cache, queue, mail, dan kredensial platform admin sebelum migrasi. Jangan menjalankan demo seeder di production kecuali memang diperlukan.

## 3. Worker dan scheduler

Salin `deploy/supervisor.conf` ke konfigurasi Supervisor lalu sesuaikan user dan path. Tambahkan cron:

```cron
* * * * * cd /var/www/pandupos-enterprise && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler menjalankan expiry subscription, notifikasi tertunda setiap lima menit, eskalasi transaksi lewat jatuh tempo setiap jam, pengingat bisnis pukul 08:00, backup database pukul 01:30, dan IndexNow pukul 02:45.

Konfigurasi provider integrasi dibuat dari menu **Integrasi**. Nama provider, format API, base URL, header tambahan, endpoint, model, tarif, dan kredensial ditentukan operator. Kredensial disimpan terenkripsi dan tidak dikembalikan dalam response aplikasi. Pemetaan provider per fitur wajib dipilih operator; aplikasi tidak menetapkan vendor atau model bawaan.

## 4. Web server

Gunakan `deploy/nginx.conf`, ganti domain serta path, lalu uji dengan `nginx -t`. Root web harus menunjuk ke direktori `public`, bukan root repository.

## 5. SEO dan domain

Setelah domain final ditetapkan:

1. Ubah `APP_URL`.
2. Buat key IndexNow, isi `INDEXNOW_KEY`, lalu ganti isi `public/indexnow-key.txt` dengan nilai key yang sama.
3. Isi `INDEXNOW_ENDPOINTS` melalui environment dengan daftar endpoint yang dipilih operator.
4. Buka `/sitemap.xml`, validasi sitemap index beserta 110 chunk pSEO, lalu submit URL index ke Google Search Console.
5. Pastikan `/robots.txt`, canonical, dan Open Graph menghasilkan domain production.

Katalog pSEO menyediakan 1.000.000 URL pertumbuhan dan 100.000 URL penjualan source code. Jangan mengubah dimensi katalog langsung di production tanpa menghitung ulang jumlah chunk dan ukuran XML.

## 6. Portal pelanggan

Portal self-service tersedia di `/portal/login`. Verifikasi login customer, isolasi data antar-tenant, daftar order, detail invoice, unduhan PDF, dan upload bukti pembayaran. File upload harus disimpan pada disk private dan hanya dapat diakses melalui controller yang memeriksa kepemilikan.

## 7. Verifikasi rilis

```bash
php artisan about
php artisan migrate:status
php artisan route:list --except-vendor
php artisan schedule:list
php artisan test
php artisan platform:module:health
```

Lakukan backup/restore drill, uji queue, checkout sandbox, approval transaksi di atas threshold, portal pelanggan, serta export CSV/XLSX/PDF ketiga laporan sebelum memproses uang nyata. Jalankan `npm run screenshots` pada server demo untuk memperbarui aset dokumentasi desktop dan mobile.
