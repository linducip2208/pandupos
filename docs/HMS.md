# HMS Module (Future Addon 11)

Patients, doctors, appointments, medical records, billing and pharmacy
dispense, gated by the `hms` module flag (non-core).

## Concepts

- **Patients/doctors** (`hms_patients`, `hms_doctors`): code-unique
  patients, fee-bearing doctors with optional user linkage.
- **Appointments**: future-only scheduling with same-doctor overlap guard
  (back-to-back allowed); lifecycle
  `scheduled → checked_in → completed`, cancellable, no-show tracking,
  re-schedulable from terminal states.
- **Records** (`hms_records`): one per visit, only after check-in, carrying
  diagnosis/prescription/notes.
- **Invoices**: consultation fee + pharmacy lines (tenant-owned variants,
  stock validated at creation, prices snapshotted). Partial payments
  allowed; pharmacy dispenses **once at paid-in-full** with
  `hms_dispense` provenance; overpayment refused.

## Surfaces

- UI: `/hms` (schedule board, records, invoices with payment) —
  `module:hms` plus `hms.view` / `hms.manage`.
- API: `/api/v1/hms/{patients,appointments,appointments/{id}/transition,invoices,invoices/{id}/pay,patients/{id}/history}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No insurance/bpjs claims: billing is direct patient billing.
- No bed/ward management: outpatient visits only.
