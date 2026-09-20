# Field Force Module (Future Addon 14)

Field tasks with GPS visits, photo evidence and offline-safe retries,
gated by the `fieldforce` module flag (non-core).

## Concepts

- **Tasks** (`ff_tasks`): assigned/en_route/checked_in/completed/cancelled
  with guarded transitions; optional assignee (only the assignee may check
  in), customer link and planned coordinates.
- **Visits** (`ff_visits`): GPS check-in/out with validated coordinates,
  Haversine distance in meters, notes, and photo evidence on the private
  disk (image MIME + extension + 5 MB guards). Check-out and completion
  require an open visit; completion additionally requires a checked-out
  visit.
- **Idempotency**: client-supplied keys return the original visit without
  re-running the status machine; a unique index wins insert races (loser
  re-reads the winner). Offline retries therefore never duplicate visits.
- **Task completion** requires a checked-out visit — presence without proof
  cannot close work.

## Surfaces

- UI: `/fieldforce` (tasks, GPS forms, photo upload) — `module:fieldforce`
  plus `fieldforce.view` / `fieldforce.manage`.
- API: `/api/v1/fieldforce/{tasks,tasks/{id}/checkin,visits/{id}/checkout,tasks/{id}/transition}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No live GPS tracking: discrete check-in/out points only.
- No route optimization: tasks are worked in creation order.
