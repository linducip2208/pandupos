# Third-party license audit — 2026-09-19

Scope: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`,
`public/` assets, `resources/` vendor copies. Method: `composer licenses`,
`npm ls`, manual header check for UltimatePOS markers.

## PHP (composer.json, license: proprietary for pandupos/enterprise itself)

| Package | Version | License | Redistribution OK |
|---|---|---|---|
| laravel/framework | ^13.0 | MIT | yes |
| laravel/sanctum | ^4.0 | MIT | yes |
| laravel/tinker | ^3.0 | MIT | yes |
| livewire/livewire | ^4.4 | MIT | yes |
| dompdf/dompdf | ^3.1.6 | LGPL-2.1+ | yes (library use, no fork) |
| phpoffice/phpspreadsheet | ^5.9 | LGPL-2.1+ | yes (library use) |
| picqer/php-barcode-generator | ^3.2.0 | LGPL-3.0+ | yes (library use) |
| spatie/laravel-permission | ^8.3 | MIT | yes |
| bacon/bacon-qr-code | ^3.1 | BSD-2-Clause | yes (library use, ZATCA QR SVG) |
| fakerphp/faker (dev) | ^1.23 | MIT | yes (dev only) |
| laravel/pint (dev) | ^1.27 | MIT | yes (dev only) |
| phpunit/phpunit (dev) | ^12.5 | BSD-3-Clause | yes (dev only) |

`composer audit`: no known advisories (verified 2026-09-19).

## JS (package.json)

| Package | License | Notes |
|---|---|---|
| @tabler/core | MIT | UI kit, bundled via Vite |
| tailwindcss / @tailwindcss/vite | MIT | build-time |
| vite / laravel-vite-plugin | MIT | build-time |
| axios | MIT | runtime |
| concurrently / playwright (dev) | MIT / Apache-2.0 | dev only |

## UltimatePOS check

- `grep -ri ultimatepos --include='*.php' --include='*.js' --include='*.blade.php' app resources routes Modules public` → no matches
  except `docs/ULTIMATEPOS_PARITY.md` (workflow/reference doc only).
- No `ultimatepos/`, `ultimate-pos`, `pos_ultimate` directories, zips, or
  minified copies found in `public/`, `resources/`, `Modules/`.
- Conclusion: no proprietary UltimatePOS code/assets copied into PanduPOS.
  UltimatePOS remains workflow/reference only.

Evidence: `bash scripts/scan-secrets.sh` PASS, `composer audit` clean,
`composer licenses --format=json` reviewed, grep above empty.
