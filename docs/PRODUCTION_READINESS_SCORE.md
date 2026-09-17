# Production Readiness Score

Updated: 2026-09-17.

This file is the canonical score ledger. Other readiness documents mirror these percentages; scores change only with repository and automated-test evidence.

| Dimension | Score | Evidence |
|---|---:|---|
| Core parity | 70% | Product Master and unit conversion are end-to-end across purchasing, POS and Sales Order; later core sprints remain |
| SaaS parity | 46% | Functional control-plane foundations; billing and automation incomplete |
| Addon parity | 1% | No requested addon meets the completeness rule |
| Test readiness | 70% | Product Master and unit conversion UI/integrations plus purchasing and sales document behavior are proven; later core matrices remain |
| Security readiness | 58% | Representative isolation/auth/webhook controls; full audit absent |
| Operations readiness | 27% | CI green; staging/load/restore/monitoring evidence absent |
| Production readiness | 26% | Weighted mandatory release-gate completion |
| Commercial readiness | 20% | Product, operational and redistribution gates remain open |

## Decision

Status: **PARTIAL — NO-GO for v1.0.0 or commercial production**.

The canonical evidence and calculation policy are in [CURRENT_STATE_AUDIT.md](CURRENT_STATE_AUDIT.md), [ULTIMATEPOS_PARITY.md](ULTIMATEPOS_PARITY.md), and [GAP_TO_100.md](GAP_TO_100.md). A green CI run is required but is not sufficient to raise this decision.
