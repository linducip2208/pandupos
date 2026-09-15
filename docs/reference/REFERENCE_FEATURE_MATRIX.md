# Reference Feature Matrix

> Each row: REFERENCE FEATURE | WORKFLOW (short) | KELEBIHAN | KEKURANGAN | VERSI KITA | IMPROVEMENT

| # | REFERENCE FEATURE | WORKFLOW | KELEBIHAN | KEKURANGAN | VERSI KITA | IMPROVEMENT |
|---|-------------------|----------|-----------|------------|------------|-------------|
| 1 | Business/Tenant register + seed | register → business+owner+seed defaults | 1-klik jualan | `where(business_id)` manual, JSON settings, `Admin#id` rapuh | `tenants` + `branches` + `memberships`, `TenantContext/Scope/Middleware` | RLS-ready, settings versioned, lifecycle status |
| 2 | BusinessLocation multi-branch | tambah cabang + scheme/layout/price-group | fleksibel retail/F&B | setting JSON, filter manual | `branches(tenant_id)` + `branch_settings` override | queryable, auditable |
| 3 | Products single/variable/combo/modifier | create → SKU → variasi → lokasi → opening | mencakup retail+resto | 1 tabel null-heavy, combo JSON, race SKU | `products` vs `variants/skus` vs `bundle_items` | relasional penuh, SKU service locked |
| 4 | Units + sub-unit + weighing barcode | multiplier + parse barcode | kuat grosir/sembako | tersebar, tidak konsisten | `units + unit_conversions` | terpusat, tested |
| 5 | Price groups per location | group prices + discounts | powerful B2B/B2C | kompleks, sulit audit | `price_lists` + `price_list_items` | versioned, auditable |
| 6 | Purchases PO→Receive→Return | draft/ordered/partial/received + payment | end-to-end tertaut | god-object Util, race stok, mismatch tambalan | `purchases` + immutable lines + `stock_movements` | state machine, ledger append-only |
| 7 | POS Direct + SellPos | register open → cart → pay split → FIFO map → receipt | terbukti lapangan, HPP FIFO | controller gemuk, FIFO inline lambat, oversell ditutupi | `carts` vs `sales_invoices` immutable + reservations + COGS job | tolak oversell, idempotent pay |
| 8 | Quotation/proforma/suspend/recurring | draft/quote → convert invoice | fleksibel B2B | status implisit satu kolom | `quotes/orders` vs `invoices` terpisah | state eksplisit |
| 9 | Inventory qty_available + adjustment + transfer | increase/decrease + 2-doc transfer | sederhana | dual-source drift, tanpa ledger, in_transit nyangkut | `stock_movements` ledger + `stock_on_hand` view | single truth, ship/receive |
| 10 | Lot/expiry/rack/alert | lot/mfg/exp + alert qty | penting farmasi | opsional, tidak penuh | `lots/batches` + `serials` entitas, FEFO | traceable penuh |
| 11 | Contacts unified customer/supplier/both/lead | create → balance → ledger → pay-due gabungan | hemat UI, praktis kulakan | 1 tabel 4 peran, Auth smell, balance denormalized | `contacts` + roles + addresses + ledger terpisah | policy-based, double-entry ready |
| 12 | Payments split + advance + parent/child | multi-method → register agregat → status | fleksibel grosir | logika tersebar, JSON denom, jurnal non-atomik | `payments + allocations + cash_sessions` atomik | enum ketat, idempotent |
| 13 | Cash register open/close + denominations | open → sell → close + Z-report | terkontrol harian | rekonsiliasi rapuh | `cash_sessions + cash_counts` | rekonsiliasi ketat |
| 14 | Reports 15+ jenis + export | filter → Util agregat → DataTables/Excel/PDF | coverage UMKM terbaik | PHP agregat lambat, filter manual, export sinkron | `report_marts` + cache + export async | CQRS baca, cepat |
| 15 | ModuleUtil hooks + quota | statuses → quota check → hook string | 22 modul tanpa fork, monetisasi built-in | magic-string, check di controller, circular | `ModuleRegistry` + events + `EntitlementService` middleware | kontrak interface, terpusat |
| 16 | Package (Superadmin) | CRUD kuota + flags + permissions | mudah dipahami | hardcoded kolom, JSON businesses, tanpa versioning | `plans + plan_entitlements` | tanpa alter untuk fitur baru, unlimited=null |
| 17 | Subscription approved/waiting/declined | pay → confirm gateway → approved berantai | antrean otomatis, snapshot kebal | tanpa dunning/prorata/invoice, edit manual | `subscriptions` + `subscription_events` + scheduler+grace | renew otomatis, audit immutable |
| 18 | Coupon fixed/percentage | code + packages/business JSON → price vs original | audit harga asli vs bayar | tanpa kuota/per-user/redemptions, validasi UI saja | `coupons + redemptions` atomik | server-side, anti abuse |
| 19 | Affiliate full (click→referral→commission→payout) | middleware capture → cookie → unique referral → first-purchase commission → payout saldo | idempoten, anti double, terbaik di modul | hanya first purchase, tanpa tier/refund reversal | pertahankan pola + recurring/tier/payout-request | anti self-referral, cap |
| 20 | Communicator broadcast | recipients → owners → notif + log teks | sederhana + audit | teks JSON, tanpa segmentasi/schedule/WA | `announcements + deliveries` + queue | segmentasi plan/trial/expired |
| 21 | Frontend pages mini-CMS | CRUD slug → `/page/{slug}` + header | cukup About/Terms | tanpa versioning/SEO | `pages(slug,locale,status)` minimal | tidak overbuild |
| 22 | Gateways 8 drivers sync callback | charge/capture/redirect→verify sekali | banyak regional | `.env` editor, 900-baris controller, tanpa webhook, typo | `PaymentGatewayInterface` + webhook HMAC idempotent + vault | tidak gantung, anti dobel |
| 23 | Package permissions custom | modul daftarkan izin → checkbox → snapshot | ekstensibel tanpa ubah inti | drift nama, tanpa versioning | `EntitlementKey` registry + middleware | versioned, comparable |
| 24 | Expiry alert artisan H-X | DATEDIFF=X → notify, skip jika ada next | anti-spam next-sub | sekali saja, tidak index-friendly | `RenewalReminder[7,3,1,0,-3]` + job harian | follow-up + grace |
| 25 | Usage limits 4 angka + resto flags | definisi → snapshot → enforcement tambah | menjawab POS | tanpa meter, warning, overage; `0`=infinite ambigu | `UsageMeter + EntitlementLimit` + dashboard 80/90/100% | soft-limit, overage ready |

## Design Decisions (from matrix)
- Tenant isolation enforced (Scope + Middleware + tests), never manual `where` only.
- Stock = ledger, never mutable qty column as truth.
- Sales = immutable invoices + idempotent payments, never inline FIFO loop.
- SaaS = Plan + Entitlement + Usage, never hardcoded quota columns.
- Billing webhooks idempotent + HMAC, never sync-callback only.
- Modules via registry + events, never magic-string core→module calls.
