# Upgrade and rollback drill — 2026-09-19

## Strategy (compatible with v1 freeze)

- Forward: deploy a new tag per [DEPLOYMENT_RUNBOOK.md](DEPLOYMENT_RUNBOOK.md);
  migrations must be **additive** past the v1 freeze (no destructive `down`
  required in this window — see `SCOPE_FREEZE.md`).
- Backward: `git checkout <previous-tag>` + `composer install` + `npm run build`
  + `php artisan optimize` + `queue:restart`. If schema/data must also revert,
  `php artisan backup:restore backup-<stamp> --force` from the pre-upgrade artifact.

## DB upgrade/rollback drill (executed 2026-09-19)

```text
# forward: apply pending additive migration
php artisan migrate --force                       → 2026_09_19_100000_add_verification_token_to_tenant_domains ... DONE
# rollback one step (drop the new column)
php artisan migrate:rollback --step=1             → 2026_09_19_100000_add_verification_token_to_tenant_domains ... DONE
# re-apply (upgrade path restored)
php artisan migrate --force                       → 2026_09_19_100000_add_verification_token_to_tenant_domains ... DONE
```

Backup-then-restore path also re-verified on the same artifact
(`backup:restore database-20260919-125024.sqlite --force` → `Restore OK`,
`health:check` 10/10 PASS).

## Result

- Upgrade path: `migrate --force` on current release — PASS.
- Rollback path: `migrate:rollback --step=1` then restore — PASS, no data
  loss beyond backup point, health green after each step.
- Policy: destructive changes require a backup and an explicit `down` reviewed
  in release audit before merge.