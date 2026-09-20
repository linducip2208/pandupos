# Project Module (Future Addon 05)

Projects with tasks, milestones, timesheets, expenses and profitability,
gated by the `project` module flag (non-core; core tenants unaffected).

## Concepts

- **Projects** (`projects`): `planned → active → completed`, with
  `on_hold` parking and `cancelled` terminal. Budget and hourly rate are
  non-negative; completing requires every task `done`.
- **Tasks** (`project_tasks`): `todo → doing → review → done` with
  send-back transitions (`doing → todo`, `review → doing`,
  `done → doing`); skips are rejected. Tasks cannot be added to finished
  projects.
- **Milestones**: dated, completed exactly once, audited.
- **Timesheets**: per user/project/day with a hard 24h daily cap enforced
  with a portable `whereDate` comparison (exact date equality is
  driver-dependent). Optional task linkage is validated against the project.
- **Expenses**: positive amounts with category and date.
- **Profitability**: `budget − (hours × hourly_rate + expenses)` with labor
  hours, labor cost, expense and remaining breakdowns.

## Surfaces

- UI: `/projects` (per-project task/time/expense forms, transitions,
  profitability) — `module:project` plus `project.view` / `project.manage`.
- API: `/api/v1/projects{/,/{id}/transition,/{id}/tasks,/tasks/{id}/advance,/{id}/time}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No per-user rates: one hourly rate per project.
- No billing integration: profitability is informational; invoicing stays in
  sales.
