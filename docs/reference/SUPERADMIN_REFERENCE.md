# Superadmin SaaS Reference (Behavior — NOT Code Source)

> Module: `Superadmin` (alias `superadmin`, v6.5), `modules_statuses.json` active. Read-only audit, no code copied.
> Covers: Package, Subscription, Business management, Pricing, Coupon, Affiliate, Communicator, Frontend pages, Payment gateways, Package permissions, Expiry, Usage limits.

## 1. Package
REFERENCE FEATURE: CRUD paket (kuota location/user/product/invoice + resto flags bookings/kitchen/order_screen/tables + interval/count + trial + price + custom_permissions + sort_order + is_active/popular/private/one_time + custom_link + businesses JSON).
WORKFLOW: index/create/store/edit/update/destroy (GET destroy). List 20/page sort_order. Private hanya superadmin. Harga dinormalisasi.
KELEBIHAN: Kuota+durasi+trial+tampil+privat dalam satu tabel, mudah dipahami.
KEKURANGAN: Kuota hardcoded → alter tabel untuk fitur baru. `businesses` JSON tidak relasional. Tanpa versioning harga (histori hanya snapshot subscription).
VERSI KITA: `plans` + `plan_entitlements(plan_id, entitlement, value)` + `plan_visibilities`. Unlimited = `null` konsisten (bukan `0` ambigu). Versioned plans.

## 2. Subscription
REFERENCE FEATURE: Lifecycle `approved/waiting/declined`, antrean upcoming, offline waiting, forceActive, edit tanggal manual, riwayat.
WORKFLOW: Bisnis pilih paket → `pay()` cek permission+private+limits → `confirm()` dispatch `pay_{gateway}` → `_add_subscription()` transaksi. Online → approved + tanggal berantai (MAX end + interval + trial). Offline/PesaPal → waiting, aktif setelah approve. `forceActive` tutup lama + mulai hari ini. Hook `after_subscription_approved` (Affiliate + notif).
TABLES: `subscriptions(id, business_id, package_id, start/trial_end/end, package_price, original_price, coupon_code, package_details JSON snapshot, created_id, paid_via, payment_transaction_id, status, deleted_at)`.
KELEBIHAN: Antrean berantai otomatis, snapshot kebal perubahan paket, flow offline jelas.
KEKURANGAN: Tanpa dunning/renewal otomatis, prorata/upgrade-downgrade, invoice terpisah; status hanya 3 nilai; edit manual rawan inkonsistensi; forceActive tanpa refund.
VERSI KITA: `subscriptions(tenant_id, plan_id, status[trialing/pending/active/past_due/grace_period/suspended/cancelled/expired], billing_cycle[monthly/quarterly/semiannual/yearly/lifetime], starts_at, trial_ends_at, current_period_start/end, cancelled_at, ends_at, metadata)` + `subscription_events` audit immutable + scheduler renew + grace read-only.

## 3. Business Management (by Superadmin)
REFERENCE FEATURE: List semua bisnis + subscription aktif, filter (package, expiry 30/7/3, expired, subscribed, is_active, last_transaction/no_transaction_since), toggle active, users list, reset password + email, create/destroy.
KELEBIHAN: Satu layar operasional lengkap.
KEKURANGAN: Join berat di controller, filter tanggal tersebar, hapus via GET rentan CSRF, tanpa suspend beralasan.
VERSI KITA: `TenantAdmin` + `lifecycle_status + suspension_reason` + audit log. Destruktif via POST + policy + confirm + re-auth.

## 4. Pricing / Public Catalog
REFERENCE: `/pricing` + AJAX duration filter, `listPackages(exclude_private)`, partials `package_card/packages/pay_*`.
KEKURANGAN: Tanpa matriks perbandingan dinamis, multi-currency/pajak, SEO.
VERSI KITA: Pricing dari `Plan+Entitlement` + toggle monthly/yearly + kalkulator kuota + CTA trial vs upgrade.

## 5. Coupon
REFERENCE: `fixed/percentage`, `applied_on_packages/business JSON`, `expiry_date`, `is_active`. Saat bayar simpan `package_price` vs `original_price`.
TABLES: `superadmin_coupons(id, coupon_code, discount_type, discount, expiry_date, applied_on_packages/business JSON, is_active)`.
KEKURANGAN: Tanpa kuota pemakaian, per-user limit, stackable, validasi server-side, redemptions table; JSON tanpa FK.
VERSI KITA: `coupons + coupon_redemptions(coupon, tenant, subscription, amount)` + validasi atomik (percentage/fixed, specific/all plans, max redemption, per-user, valid_from/until, first/recurring).

## 6. Affiliate (best-designed part, keep pattern)
REFERENCE: Pendaftaran pending → approve (code 6-char) / reject / suspend. Setting di `system` (enabled, commission_%, cookie_days, min_payout, terms). Middleware `CaptureAffiliateReferral` catat `affiliate_clicks` + cookie `affiliate_ref=CODE|expiry`. Atribusi registrasi cek cookie + expiry server + unique `referred_business_id`. Komisi pembelian pertama jika enabled+approved+price>0+referral+belum pernah (unique) → `commissions(pending)` snapshot. Kurasi approve/cancel (cegah un-approve jika paid). Payout validasi `owed>0, amount<=owed, >=min` → `payouts(paid)` + email. Saldo = approvedTotal - paidTotal.
TABLES: `affiliates(user_id, code unique, status, tc_accepted_at)`, `affiliate_clicks`, `affiliate_referrals(referred_business_id unique)`, `affiliate_commissions(referred_business_id unique, sale/percent/amount, status pending/approved/cancelled)`, `affiliate_payouts(amount, bank ref, paid, paid_at, created_by)`.
KELEBIHAN: Idempoten, unique anti double-komisi, expiry ganda, saldo-based, notif try-catch terisolasi.
KEKURANGAN: Hanya first purchase (tanpa recurring), tanpa tier, tanpa refund reversal, klik tanpa IP/UA anti-fraud.
VERSI KITA: Pertahankan pola + tambah `CommissionRule(plan, percent, recurring_months, cap)` + attribution first/last touch + `PayoutRequest(pending→paid)` + anti self-referral/duplicate/replay.

## 7. Communicator / Announcements
REFERENCE: Broadcast email/DB ke owners terpilih + log. `superadmin_communicator_logs(id, business_ids text JSON, subject, message)`. Render expiry alert + communicator popup.
KEKURANGAN: business_ids teks, tanpa segmentasi/template/scheduling/WA/push/read-receipt.
VERSI KITA: `announcements(audience_query, subject, body, channel, scheduled_at, sent_at)` + `announcement_deliveries(tenant, status, read_at)` + queue mass delivery.

## 8. Frontend Pages (mini CMS)
REFERENCE: CRUD halaman statis `/page/{slug}` + header inject `is_shown` order `menu_order`. `superadmin_frontend_pages(id, title, slug, content HTML, is_shown, menu_order)`.
VERSI KITA: Tidak dibangun ulang; pakai `pages(slug, locale, status, published_at)` minimal atau CMS existente.

## 9. Payment Gateways
DRIVERS: `stripe, paypal, razorpay, pesapal, paystack, flutterwave, myfatoorah, offline`. Gating via env/config/system + negara + currency.
WORKFLOW: Stripe charge sinkron; PayPal Orders v2 create→capture; Razorpay fetch+capture; Paystack redirect→callback verify SDK; Flutterwave callback verify cURL; MyFatoorah callback status; PesaPal session match → waiting → confirmation controller; Offline → email + waiting → manual approve. Semua callback sinkron + verifikasi sekali, TANPA webhook HMAC/async/queue/retry/idempotency. Secret di `.env` diedit dari UI (risiko overwrite, butuh 644).
VERSI KITA: `PaymentGatewayInterface` (Manual/Cash dulu) + adapters Midtrans/Xendit/Duitku/Tripay/iPaymu + BYOK encrypted, never expose secret. `billing_transactions/invoices/items + payment_attempts + payment_webhooks` terpisah dari POS payments. Webhook idempotent (gateway_ref unique) + HMAC + retry + delivery history.

## 10. Package Permissions
REFERENCE: Izin dinamis via `ModuleUtil::getModuleData('superadmin_package')` → checkbox → `custom_permissions array` → snapshot ke `package_details` → enforcement via limits + sidebar/middleware.
KEKURANGAN: Tanpa UI perbandingan, versioning, drift jika nama berubah.
VERSI KITA: `EntitlementKey` registry (`pos.access`, `inventory.multi_warehouse`, `manufacturing.bom`, `ai.forecasting`, dll) + `PlanEntitlement` + `TenantOverride` + middleware `entitlement:xxx` + `ModuleRegistry`.

## 11. Expiry & Alerts
REFERENCE: `pos:sendSubscriptionExpiryAlert` (hanya `env=live`) baca `package_expiry_alert_days` default 5 → cari `DATEDIFF(end,today)=X` + `start<=today` → skip jika ada next subscription → notify owner. UI badge + modal expired + max_location modal.
KEKURANGAN: Hanya sekali H-X, tanpa H-7/H-1/expired follow-up, query tidak index-friendly.
VERSI KITA: `RenewalReminder(subscription, trigger_at[7,3,1,0,-3], channel, sent_at)` + job harian + grace read-only + dashboard expiring/failed.

## 12. Usage Limits
REFERENCE: 4 kuota angka + 4 resto flags + custom. Definisi paket → snapshot → enforcement saat tambah user/location/product/invoice + blokir upgrade jika over-limit.
KEKURANGAN: Tanpa meter aktual, soft-limit/warning, overage billing; infinite = `0` ambigu.
VERSI KITA: `UsageMeter(tenant, metric, period, count)` + `EntitlementLimit(plan, metric, limit, overage_price)` + `UsageLimitService::assertCanCreate()` + dashboard `8/10`, alerts 80/90/100%.

## 13. Settings / Dashboard / Views
Settings: campuran `system` + tulis ulang `.env` (gateways, pusher, maps, backup, captcha) + partials (application, gateways, smtp, pusher, cron, backup, js/css). Menampilkan `cron_job_command`. Dashboard: kartu tahun/bulan/minggu, `not_subscribed`, chart 12 bulan `SUM package_price approved`, AJAX new_subs/regs. Views flow terpisah superadmin vs bisnis via middleware.
VERSI KITA: `SystemSetting(key,type,encrypted)` typed, dashboard Livewire dengan MRR/ARR/New/Expansion/Churn, KPI trial/suspended/expiring/failed, charts revenue/MRR/tenants/plan-distribution/growth/churn, tables recent/expiring/failed/top. Tanpa `.env` editor.
