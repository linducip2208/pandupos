# Offline Sync — PanduPOS (backend, Flutter-ready)

Tables: `devices (tenant_id, uuid, name, platform, last_sync_at, revoked_at)`, `server_change_logs (tenant_id, entity, entity_uuid, operation, changed_at)`, `idempotency_keys (tenant_id, key unique)`.

Push (`POST /api/v1/sync/push`): `{device_uuid, mutations: [{uuid, entity, operation, payload, client_timestamp}]}`. Server validates UUIDs, registers device, checks idempotency (`tenant:entity:uuid` → `duplicate`), rejects `update` on immutable `sales_invoice/payment` with `conflict: immutable_entity_use_void_or_return`, otherwise stores idempotency key + change log → `applied`.

Pull (`GET /api/v1/sync/pull?cursor&limit&since`): cursor-based `{data, meta: {next_cursor, has_more}}`.

Conflict strategy: master data → version/`updated_at` check; sales/payments → append-only + UUID dedupe; stock → movement commands only, never overwrite; completed invoices immutable except void/return.

Future Flutter local (SQLite): products, variants, customers, sales, sale_items, payments, register, sync_queue, sync_state.
