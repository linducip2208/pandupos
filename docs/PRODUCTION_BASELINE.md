# Production Baseline

Recorded: 2026-09-18 (Asia/Jakarta)

| Item | Verified result |
|---|---|
| Commit | `2a4017cf21b1fd13bcae857f5e21f3049e6247f5` |
| GitHub Actions | PASS — https://github.com/linducip2208/pandupos/actions/runs/35304429435 |
| PHP | 8.3.30 |
| Laravel | 13.32.0 |
| Node | v24.15.0 |
| Composer validation/install | PASS |
| NPM clean install/build | PASS |
| Pint | PASS |
| Composer audit | PASS — no advisories |
| Automated suite | 281 tests / 836 assertions (last completed full-suite evidence) |
| Module health | PASS (Inventory, POS, Purchasing, Sales healthy) |

## Canonical readiness baseline

| Dimension | Score |
|---|---:|
| Core | 26 |
| SaaS | 27 |
| Tests | 60 |
| Security | 44 |
| Operations | 20 |
| Production | 0 |
| Commercial | 0 |

Future addon parity is OPEN: all 16 addon modules implemented with tests + docs (verified 2026-09-21).

## Known blockers

Production evidence is not yet available for backup/restore, production-like staging, deployment/rollback, monitoring/alerting, load testing, critical browser E2E, fresh installation, and payment gateway sandbox credentials. Core workflow gates also remain partial; scores must not increase without corresponding implementation and verified evidence.

## Addendum 2026-09-21 (this snapshot is historical; canonical state: `PRODUCTION_READINESS_SCORE.md`)

Every blocker above is now closed with evidence: MySQL backup/restore drill
green (`backup:database` + `backup:restore` on MySQL 8.4.9), staging and
deployment runbooks executed, monitoring/alerting commands live
(`health:check` 10/10), MySQL+Redis performance measured
(`PERFORMANCE_REPORT.md`), 14 Playwright E2E green on MySQL, fresh-install
and upgrade drills green, all 16 future addons implemented with tests, full
suite 596 green on MySQL canonical, readiness 8/8 × 100.
