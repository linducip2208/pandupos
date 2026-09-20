# HRM Module (Future Addon 07)

Departments, employees, attendance, leave and holidays, gated by the `hrm`
module flag (non-core; core tenants unaffected).

## Concepts

- **Departments** (`hrm_departments`): name-unique per tenant, optional
  manager.
- **Employees** (`hrm_employees`): code-unique per tenant (auto
  `EMP-0001…` when blank), optional department/user linkage (one employee
  per user), statuses active/inactive/terminated.
- **Attendance** (`hrm_attendances`): one row per employee/day; check-in
  flags `late` after 09:00; check-out records hours from the absolute
  minute difference (Carbon 3 diffs are signed); double in/out refused.
- **Leave** (`hrm_leaves`): annual (12/yr) / sick (12/yr) / unpaid
  (unlimited) with balance, overlap and range guards. Approval stamps one
  `leave` attendance row per day so attendance reports stay consistent;
  decisions are single-shot and audited.
- **Holidays** (`hrm_holidays`): date-unique per tenant.

## Surfaces

- UI: `/hrm` (balances, today board, leave queue, registration, holidays)
  — `module:hrm` plus `hrm.view` / `hrm.manage`.
- API: `/api/v1/hrm/{employees,employees/{id}/attend,employees/{id}/leave,leaves/{id}/decide}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- Leave days are calendar days inclusive (holidays do not reduce the count).
- No shift/overtime rules: late cutoff is a fixed 09:00.
- No payroll linkage here: salary lives in Payroll, which reads HRM
  attendance/leave as source data.
