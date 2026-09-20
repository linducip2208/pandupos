# Payroll Module (Future Addon 08)

Salary structures, monthly runs, payslips and accounting integration,
gated by the `payroll` module flag (depends on `hrm`; non-core).

## Concepts

- **Structures** (`payroll_structures`): one per employee — base salary,
  allowance/deduction item lists (validated name + non-negative amount),
  income-tax rate 0–1.
- **Runs** (`payroll_runs` + `payroll_run_lines`): one per tenant/month;
  creation snapshots every structure into immutable lines (gross, tax,
  deductions, net) with HRM present-day context. Lifecycle
  `draft → approved → paid`; approval locks lines and posts to accounting
  when that module is enabled.
- **Accounting post** (on approve, inside the transaction): Dr 5210 Beban
  Gaji (gross) / Cr 2150 Hutang Gaji (net) / Cr 2200 Pajak (withheld tax) /
  Cr 2160 Hutang Potongan Gaji (deductions). Balanced by construction;
  skipped entirely when accounting is off.
- **Payslips**: per-employee breakdown from the snapshot (never recomputed
  from live structures).

## Surfaces

- UI: `/payroll`, `/payroll/runs/{run}/payslip/{employee}` —
  `module:payroll` plus `payroll.view` / `payroll.manage`.
- API: `/api/v1/payroll/{runs,runs/{id}/transition,runs/{id}/payslip/{employee}}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No pro-rating by attendance: lines snapshot full structure amounts;
  present days are informational on the slip.
- No bank disbursement file: `paid` is a status stamp, not a transfer.
