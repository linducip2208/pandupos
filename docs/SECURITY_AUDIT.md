# Security audit evidence

## 2026-09-18: repository secret scan

Scope: all tracked repository files, excluding the scanner itself. The CI job
executes `bash scripts/scan-secrets.sh` on every push and pull request. It
fails on cloud access keys, private-key blocks, payment secret keys,
Slack/GitHub/browser API keys, `aws_secret_access_key`, `database_url`,
`db_password` patterns, plus forbidden tracked files (`.env`, `*.sql`,
`*.dump`, `*.pem`, `*.key`, `database.sqlite`).

Result 2026-09-19: PASS (`bash scripts/scan-secrets.sh` clean).
`composer audit` clean. `.env.example` contains placeholders only.
Production seeding refuses default platform-admin password unless
`PLATFORM_ADMIN_PASSWORD` is set.

## 2026-09-19: web security audit (WebSecurityTest, 9 tests PASS)

- CSRF: `POST /login` without token returns 302/419/422, never silent success.
- XSS: `e()` escapes `<script>`; no `{!! $_GET/$_POST/$_REQUEST !!}` in `resources/views`.
- Mass assignment: `tenant_id` in body ignored; `TenantMiddleware` never trusts
  body/query; `TenantContext::idOrFail()` fail-closed.
- SQLi: `GET /api/v1/products?search=' OR '1'='1` returns 200 without Tenant-B data.
- Open redirect: `/login?next=http://evil.example.com` renders login, never redirects externally.
- Password reset: `/password/reset` and `/password/email` are 404 (no weak flow exposed).
- Session: customer guard cannot access `/dashboard` (403 after hardening
  `DashboardController@index` with `abort_unless(User)`); staff cannot access
  `/portal/*` (302/403).
- Rate limit: `throttle:300,1` on `api/v1` emits `X-RateLimit-*` headers.
- Dangerous inputs: 5000-char contact name validated (422 or bounded).

Fix applied: `app/Http/Controllers/DashboardController.php` now aborts 403 for
non-staff sessions (was 500 on `CustomerLogin::getRoleNames()`).

## 2026-09-19: file security audit (FileSecurityTest, 5 tests PASS)

- Payment proof: `mimes:jpg,jpeg,png,pdf`, `max:5120`; `.php` and 6MB PDF rejected.
- Product image: `image`, `max:2048`, stored `products` on `public` disk; `.php` rejected.
- Storage: proofs under `portal-payment-proofs/{tenant_id}` on private `local` disk.
- Ownership: A uploading to B invoice → 404; A viewing B invoice → 404.
- Traversal: `../../evil.jpg` sanitized to `portal-payment-proofs/...`, no `..`.
- Public exec: `storage/app/public/products/*.{php,phtml,phar,htaccess,sh}` absent.

## 2026-09-19: API security audit (ApiSecurityTest, 7 tests PASS)

- Revocation: token delete removes `personal_access_tokens` row (verified in DB).
- Expiry: token with past `expires_at` is 401.
- Tenant isolation: loner with `X-Tenant-ID` of foreign tenant → 403.
- RBAC: viewer without `sales.view`/`pos.sale.create` → 403 on `GET/POST /sales`.
- Body rejection: `tenant_id` in body never creates cross-tenant rows.
- Replay: same `Idempotency-Key` on `POST /sales` returns same invoice id.
- Errors: 404 shape contains no stack trace / `APP_KEY`.
- Health: `/api/v1/platform/health` leaks no `APP_KEY`/`DB_PASSWORD`/`MYSQL_PWD`.

## 2026-09-19: sensitive admin hardening (SensitiveAdminTest, 3 tests PASS)

- `/platform/*` and `/api/v1/platform/*` require `platform-admin` (owner → 403).
- Impersonation requires `platform.tenants.impersonate`, creates
  `impersonation_sessions` row + `audit_logs impersonation.started`.
- `audit_logs` never stores `password` actions or `APP_KEY` material.

No Critical/High open findings. Residual note: Sanctum tokens have no scopes
yet (`abilities=["*"]`); revocation + expiry + tenant/RBAC gates are enforced.
