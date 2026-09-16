# Release Checklist — PanduPOS Enterprise

Branch hardening: `feat/platform-hardening` → `main`.
Terakhir diverifikasi: 2026-09-16 (42 tests, pint PASS, audit clean).

## 1. Pre-release (wajib hijau)

```bash
composer validate --strict
./vendor/bin/pint --test
composer audit
php artisan test
php artisan platform:module:health
php artisan migrate --pretend
```

Catatan composer:
- `name: pandupos/enterprise`, `license: proprietary` (bukan skeleton laravel/laravel).
- `minimum-stability: dev` DIPERTAHANKAN karena Laravel 13 + `ramsey/uuid 4.x-dev` belum stable.
  Jangan paksa `stable` sebelum semua deps stable, atau `composer update --lock` gagal resolusi.
- `prefer-stable: true` tetap aktif untuk meminimalkan dev packages.

## 2. Security tenant-escape (sudah di-fix di branch ini)

- [x] `TenantContext::idOrFail()` fail-closed dipakai di semua controller tenant + Livewire + service.
- [x] `PurchaseService::receive($id, $lines, $tenantId)` constrain `where tenant_id`; `createDraft` validasi warehouse/contact/variant milik tenant.
- [x] `SaleService::void/return` constrain `where tenant_id`; validasi over-return; void restore net (sold - returned).
- [x] Validasi `Rule::exists()->where('tenant_id')` di Product/Purchase/Sale/Catalog.
- [x] `TenantMiddleware` guest hanya dari route binding, tidak dari header.
- [x] `ImpersonationController` verifikasi target member tenant + paksa `current_tenant_id`.
- [x] `StockMovement` append-only di model layer (block update/delete).
- [x] `CouponService` global limit pakai `withoutGlobalScopes()`.

Sisa P1 (belum wajib rilis, tapi di-backlog):
- Keluarkan `tenant_id/is_platform_admin/current_tenant_id` dari `$fillable`, pakai `forceFill` internal.
- Tambah Policy untuk Sale/Purchase/Contact/Catalog (saat ini hanya ProductPolicy).
- Tambah test: POST dengan `tenant_id=B`, receive/return/sync/report cross-tenant, header-vs-current, suspended/archived.

## 3. Race stok (sudah di-fix di branch ini)

- [x] `SaleService::checkout` sort lines by `variant_id` + catch `QueryException 23000` → return existing (tidak 500).
- [x] `SyncController::push` catch `23000` → duplicate, bukan 500.
- [x] `StockService::transfer` sort lock order (cegah deadlock A->B vs B->A).
- [x] `lockVariant` fail-closed (abort 422 jika warehouse/variant bukan milik tenant).
- [x] `return()` validasi `qty <= sold - already_returned`; `void()` restore net saja.
- [x] Test `CriticalBusinessTest::test_sale_decreases...` diperbaiki: 10-3+1=8, void → 10 (bukan 11 double-restore).

Sisa P2 (butuh migrasi DB, jangan di rilis ini tanpa downtime plan):
- `UNIQUE(stock_movements: tenant,ref_type,ref_id,variant,warehouse)` + `CHECK quantity>0`.
- `idempotency_key NOT NULL` + required header.
- Concurrent test 2 koneksi (oversell race, duplicate-key konkuren, double-receive).

## 4. Deploy

1. `composer install --prefer-dist --no-progress --no-dev --optimize-autoloader`
2. `npm run build`
3. `php artisan migrate --force`
4. `php artisan optimize`
5. Worker: `php artisan queue:work --tries=1` (Redis di prod, `database` hanya lokal)
6. Scheduler cron: `php artisan schedule:run` tiap menit (expiry/renewal, trial reminders, health)
7. `php artisan storage:link`, cek mail, backup restore drill.

## 5. Post-deploy smoke

- trial → subscribe → enable POS → PO → receive → jual → return → void → billing
- cek tenant A tidak bisa baca tenant B (web + API)
- cek disabled module / expired subscription → 403
- cek duplicate `Idempotency-Key` → 1 invoice
