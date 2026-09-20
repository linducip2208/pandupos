# Browser E2E (Playwright, real Chromium, MySQL)

Critical user journeys are driven in a real browser against a MySQL-seeded
demo tenant. This suite is what flipped the `Critical browser E2E` gate; it
has already caught production-class bugs that HTTP-kernel tests cannot see
(Livewire update route missing tenant context → POS 403 for every real user;
register close rejecting blank optional denominations; cash tender overpay
422 instead of change).

## Coverage (`e2e/`, `playwright.config.js`)

| Spec | Path |
|---|---|
| Login success + wrong-password error + dashboard | `01-auth.spec.js` |
| Product create → PO create/submit → receive → register open → POS checkout (cash tender + change) → session close | `02-critical-path.spec.js` |
| Business / finance / operations reports render | `03-reports.spec.js` |
| Platform admin plan change incl. password re-confirmation | `04-saas.spec.js` |

## Run locally (MySQL 8)

```powershell
mysql -u root -e "DROP DATABASE IF EXISTS pandupos_e2e; CREATE DATABASE pandupos_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='pandupos_e2e'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''
$env:APP_ENV='local'; $env:PLATFORM_ADMIN_EMAIL='admin@e2e.local'; $env:PLATFORM_ADMIN_PASSWORD='E2eAdmin123!'
php artisan migrate:fresh --seed --force
```

Serve the E2E database on a dedicated port (never reuse a dev server port):

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3306'
$env:DB_DATABASE='pandupos_e2e'; $env:DB_USERNAME='root'; $env:DB_PASSWORD=''; $env:APP_ENV='local'
Start-Process -FilePath 'php' -ArgumentList 'artisan','serve','--host=127.0.0.1','--port=8766' -WindowStyle Hidden
$env:E2E_BASE_URL='http://127.0.0.1:8766'; npm run test:e2e
```

First run installs nothing extra: Chromium is provisioned via
`npx playwright install chromium` (already vendored under
`%USERPROFILE%\AppData\Local\ms-playwright` on dev machines; CI installs it
in the `e2e` job).

## Design rules

- Specs are serial (`workers: 1`) and self-cleaning: unique names per run
  (`Date.now()` stamp), leftover register sessions are closed before opening.
- No test-only backdoors: every step uses the same routes, forms, policies
  and services as production. Failures mean product bugs — fix the product.
- The suite runs on MySQL by policy (SQLite behavior is not accepted as
  production evidence for browser flows).
