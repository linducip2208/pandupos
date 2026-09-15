# UltimatePOS Feature Audit (Behavior Reference — NOT Code Source)

> Reference: UltimatePOS V7.3 codebase (Laravel 9.51, `business_id` multi-tenancy, Blade + jQuery DataTables, `nwidart/laravel-modules`, `spatie/laravel-permission`, Passport).
> Rule: This document describes BEHAVIOR and BUSINESS FLOW only. No proprietary code, Blade, controller, migration, or asset was copied. PanduPOS implements everything independently.
> Reference location (read-only): `D:\project laravel\pandupos` (clone) / original CodeBase-V7.3.
> New platform: `D:\project laravel\pandupos-enterprise` (Laravel 13, zero runtime dependency on reference).

Format per fitur: REFERENCE FEATURE / WORKFLOW / KELEBIHAN / KEKURANGAN / VERSI KITA / IMPROVEMENT.

---

## 1. Tenant / Business (`business`, `business_locations`, `users`)

REFERENCE FEATURE
- Registrasi bisnis + owner otomatis, multi-cabang (`business_locations` dengan invoice scheme/layout, price group, printer, payment accounts JSON), multi-user + role per bisnis (`Admin#<id>`), batas lokasi per user, settings JSON raksasa di `business` (currency, tax, FIFO/LIFO, POS, receipt, email/SMS).

WORKFLOW
1. Register → create business + owner + role Admin.
2. Seed default: role Cashier, Walk-In Customer, InvoiceScheme/Layout Default, Unit Pcs, NotificationTemplate.
3. Tambah cabang → pilih scheme/layout/price-group/printer.
4. Tambah user → assign role + lokasi + selected_contacts.
5. Session `business.id` dipakai semua Util untuk format/aturan.

KELEBIHAN
- Onboarding 1-klik langsung bisa jualan. Price-group per cabang fleksibel. Permission per lokasi cocok untuk kasir/gudang.

KEKURANGAN
- Isolasi hanya `where(business_id)` manual, tanpa Global Scope/RLS → mudah bocor.
- Model membaca `auth()/session()` langsung → tidak reusable di queue/API.
- Settings JSON tidak queryable/versioned/auditable. Role name parsing `Admin#id` rapuh. Superadmin sebagai modul, bukan control-plane.

VERSI KITA
- `tenants` + `branches` + `memberships(user_id, tenant_id, branch_ids[], role)`. `TenantContext` + `TenantScope` + `TenantMiddleware`, `tenant_id` UUID + `id` bigint.
- `tenant_settings` key-value berversi + override per branch. RBAC murni + ABAC lokasi. Seed via `TenantProvisioningService` + event `TenantCreated`.

IMPROVEMENT: RLS-ready, tenant context eksplisit, settings terstruktur, lifecycle status (trial/active/past_due/suspended/cancelled/archived).

## 2. Products / Variations

REFERENCE FEATURE
- 4 tipe: `single, variable, combo, modifier`. Variable = `product_variations` → `variations`. Combo = JSON `combo_variations`. Modifier untuk resto. Brand, category 2-level, unit + sub-unit multiplier, tax incl/excl, warranty, rack, media ganda, `not_for_selling`, expiry, secondary_unit, weighing-scale barcode.

WORKFLOW
1. Create product → validasi SKU unik, generate SKU/Sub-SKU.
2. Buat variasi + harga + assign lokasi + opening stock.
3. Harga lapis: `variation_group_prices` + `selling_price_groups` + `discounts`. Quick-add dari POS, bulk-edit, import Excel, toggle Woo sync.

KELEBIHAN
- Satu model mencakup retail+resto+manufaktur ringan. Sub-unit + weighing barcode kuat untuk grosir.

KEKURANGAN
- Satu tabel untuk semua tipe → banyak null + branching di Util. Combo JSON bukan relasi → sulit audit stok. Race SKU duplikat. Kategori hanya 2 level. Media inkonsisten (public/uploads + polymorphic).

VERSI KITA
- `products` (master) vs `product_variants/skus` vs `bundle_items` relasional. `units + unit_conversions` + `price_lists` terpisah. SKU service dengan lock/sequence per tenant. Kategori closure-table N-level. Media di object storage.

## 3. Purchasing

REFERENCE FEATURE
- Supplier, draft/received/ordered/partial, multi-payment, pajak inline, diskon baris, biaya tambahan, kurs, lot/expiry, return, PO/requisition linkage. `hidePurchasePrice` untuk gudang.

WORKFLOW
1. Pilih supplier + lokasi + currency → tambah baris → optionally update harga jual.
2. Store → hitung total → create purchase_lines → tambah `qty_available` jika `received`.
3. Catat payment → update `due/partial/paid/overdue`. Edit → adjust mapping jual; Delete → kembalikan stok jika belum terjual.

KELEBIHAN
- End-to-end PO→Purchase→Return tertaut. Expiry/lot + tax incl/excl penting untuk farmasi/retail.

KEKURANGAN
- `ProductUtil` god-object. Update stok `+= delta` tanpa locking → race. Edit yang sudah termapping rumit (`fixMismatch` sebagai tambalan). Multi-currency inkonsisten.

VERSI KITA
- `purchases` header + `purchase_lines` immutable + `stock_movements` append-only. State machine `draft→ordered→partial→received→closed`. Edit hanya via koreksi/credit-note. Optimistic locking + idempotency-key.

## 4. Sales / POS

REFERENCE FEATURE
- Direct Sale + POS cepat: draft/quotation/proforma/final, suspend, modifier, meja resto, tipe layanan, delivery, diskon global+baris, pajak, reward, komisi agen, recurring invoice, receipt template, split payment + payment link, invoice token public.

WORKFLOW
1. Wajib register terbuka → load location/price-group/scheme/customer walk-in.
2. Suggestion produk → cek qty, harga grup, pajak, lot.
3. Store dalam transaksi: hitung total → create sell → payment lines → register agregat → update status → map FIFO/LIFO (`sell→purchase_lines`) → kurangi stok → receipt URL.
4. Update/delete → kembalikan mapping + stok + refund register. Quotation → convert to invoice.

KELEBIHAN
- Alur kasir terbukti lapangan (suspend, recent, shortcut, featured). Mapping FIFO/LIFO untuk HPP + gross profit.

KEKURANGAN
- Controller POS >30 method, Util ratusan baris → sulit test. FIFO loop inline → lambat/deadlock, `adjustStockOverSelling` menutupi oversell. Status implisit satu kolom. Receipt HTML campur bisnis+presentasi. Public token tanpa expiry/scope.

VERSI KITA
- `carts/orders` vs `sales_invoices` immutable. `inventory_reservations` saat keranjang, `commit` saat bayar (tolak oversell). COGS via ledger + job antrian. Receipt template service + signed URL. Payment Intent abstraction. Idempotency-key anti double-charge.

## 5. Inventory / Stock

REFERENCE FEATURE
- Stok per `variation × location` (`qty_available`), adjustment normal/abnormal, transfer antar cabang (sell_transfer + purchase_transfer, in_transit→final), opening stock, expiry alert, rack, history, fix mismatch.

WORKFLOW
- Purchase received / adjustment normal → increase. Sale final / abnormal → decrease. Transfer 2 dokumen. Report rekonsiliasi qty vs agregat. Expired removal + alert.

KELEBIHAN
- Model sederhana, mudah dipahami kasir. Transfer+adjustment+expiry satu pola `transaction`.

KEKURANGAN
- Dual-source of truth (qty vs agregat) → drift, butuh fixMismatch. Tanpa ledger immutable → audit lemah. Transfer tanpa 2-phase commit → nyangkut in_transit. Tanpa batch/serial penuh.

VERSI KITA
- `stock_movements(tenant, warehouse, variant, ref_type/ref_id, movement_type, qty, unit_cost, occurred_at)` satu-satunya kebenaran; `stock_on_hand` materialized. Adjustment butuh reason+approval. Transfer sebagai `transfer_orders` ship/receive. Lot/batch/expiry + serial sebagai entitas. FEFO default.

## 6. Contacts (Customer/Supplier/Both/Lead)

REFERENCE FEATURE
- Satu tabel `contacts` + Walk-In default. Credit limit, pay term, reward, ledger, customer_group diskon %, assign sales, import, peta, kirim ledger email. `payContactDue` alokasi gabungan parent/child.

WORKFLOW
- Create contact → auto contact_id → cek duplikat. Transaksi taut contact → update balance/due. Pay due gabungan. Ledger opening+invoices+payments. `view_own` filter.

KELEBIHAN
- Unifikasi menghemat UI, cocok UMKM relasi ganda. Ledger + due praktis untuk kulakan.

KEKURANGAN
- Satu tabel 4 peran → validasi kondisional kompleks. `Contact extends Authenticatable` padahal bukan user. Scope campur auth/abort di Model. Balance denormalized tanpa double-entry.

VERSI KITA
- `contacts` + `contact_types` + `contact_addresses` + `credit_profiles`. Walk-In sebagai party sistem. `receivables/payables_ledger` terpisah. Policy-based access.

## 7. Payments + Cash Register

REFERENCE FEATURE
- Multi-metode (cash/card/cheque/bank_transfer/advance/custom), split, uang muka, denominasi, akun pembayaran, parent/child alokasi, status due/partial/paid/overdue. Register open/close + Z-report.

WORKFLOW
- POS → payment lines (method/amount/account/card/cheque/bank) → agregat ke `cash_register_transactions` → hitung status vs total + pay_term → payContact alokasi → open (opening_amount) → close (closing, totals, denominations).

KELEBIHAN
- Kasir harian terkontrol. Split+advance+pay-due fleksibel untuk grosir.

KEKURANGAN
- Logika status tersebar. Denominations JSON + total manual → rapuh. Jurnal via event listener bukan atomik. Metode custom string bebas → sulit rekonsiliasi.

VERSI KITA
- `payments + payment_allocations + cash_sessions + cash_counts` dalam DB transaction. Enum ketat + `payment_accounts` rekonsiliasi. Gateway adapter Charge/Refund/Webhook. Idempotency + `payment_events` audit.

## 8. Reports

REFERENCE FEATURE
- Profit/Loss, Purchase/Sell, Stock (cost/selling), details/expiry/lot, Tax, Trending, Expense, Adjustment, Register X/Z, Sales Rep + komisi, dues, aging, product purchase/sell, grouped, resto table/staff, GST India, activity log. Export Excel/PDF.

WORKFLOW
- Filter (location/date/user/group) → Util agregat → DataTables/Blade/Export.

KELEBIHAN
- Coverage terbaik untuk UMKM, siap pajak + operasional harian.

KEKURANGAN
- Agregasi PHP + banyak query → lambat, tanpa cache/materialized. Filter lokasi manual → mudah bocor. Logika duplikat Util vs Controller. Export sinkron → timeout.

VERSI KITA
- CQRS baca: `report_marts` (daily_sales, inventory_snapshot) via worker. Pre-aggregate + Redis cache + export async (job→file→notifikasi). Otorisasi di middleware/policy.

## 9. ModuleUtil (Plugin System)

REFERENCE FEATURE
- Facade: isInstalled/Defined, isSubscribed, quota count/location/user/product/invoice, isQuotaAvailable, getModuleData hook, dropdowns, availableModules. `modules_statuses.json` 22 modul.

WORKFLOW
1. Config + statuses tentukan aktif.
2. Setiap aksi kuota → cek isQuotaAvailable → redirect/error jika habis.
3. Subscription gate fitur. Hook modul via `getModuleData($fn, $args)` (mis. Manufacturing, Accounting).

KELEBIHAN
- Ekosistem tanpa fork core. Monetisasi kuota built-in. Hook generik untuk Woo/Manufacturing/Accounting.

KEKURANGAN
- Magic-string hook tanpa interface → fragile. Quota check di controller → mudah dilewati via API. SRP dilanggar. Core→modul circular (Business→Subscription).

VERSI KITA
- Interface + event bus (`OrderPaid, StockReceived`) + `EntitlementService` terpusat di middleware. Kuota sebagai `usage_meters + plan_limits` di application service. Core tidak depend ke modul.

---

## Appendix: Key Tables (names only)
`business, business_locations, users, contacts, categories, brands, units, tax_rates, products, product_variations, variations, variation_location_details, variation_group_prices, transactions, purchase_lines, transaction_sell_lines, transaction_sell_lines_purchase_lines, transaction_payments, cash_registers, cash_register_transactions, accounts, invoice_schemes/layouts, printers, customer_groups, discounts, warranties, expense_categories, notifications, media, reference_counts, selling_price_groups, types_of_services, res_tables`.
