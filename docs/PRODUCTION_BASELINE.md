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

Future addon parity is frozen and out of scope for v1.

## Known blockers

Production evidence is not yet available for backup/restore, production-like staging, deployment/rollback, monitoring/alerting, load testing, critical browser E2E, fresh installation, and payment gateway sandbox credentials. Core workflow gates also remain partial; scores must not increase without corresponding implementation and verified evidence.
