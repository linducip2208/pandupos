# Gym Module (Future Addon 12)

Memberships, visit-capped check-ins, payments and renewal, gated by the
`gym` module flag (non-core).

## Concepts

- **Packages** (`gym_packages`): duration days, price, optional visit cap.
- **Members/trainers**: code-unique members, active-flagged trainers with
  optional user linkage.
- **Subscriptions** (`gym_memberships`): one active row per member (second
  concurrent subscription refused); price/limit snapshotted from the
  package; partial payments with balance; cancellation only while active.
  Renewal is a new row — history is preserved, never edited.
- **Check-in**: refuses inactive memberships, enforces the visit quota, and
  lazily expires past-dated memberships. The expiry update commits outside
  the attendance transaction on purpose: refusing the visit must not roll
  back the expiry, otherwise renewal stays blocked forever.
- **Payments**: positive, capped at balance; cancelled rows refuse payment.

## Surfaces

- UI: `/gym` (subscriptions, member/package forms) — `module:gym` plus
  `gym.view` / `gym.manage`.
- API: `/api/v1/gym/{memberships,subscribe,memberships/{id}/transition}`
  (sanctum + tenant scope + same policies).

## Intentional boundaries (v1)

- No class scheduling: trainers attach per visit only.
- No freeze/hold: expiry is date-driven; holds are future work.
