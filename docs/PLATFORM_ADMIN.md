# Platform Admin — PanduPOS

Terminology: Platform Admin (not Superadmin). Routes `/platform/*` behind `auth` + `can:platform-admin` plus granular `platform.*` permissions (dashboard, tenants.view/create/update/suspend/activate/impersonate, plans.manage, subscriptions.manage, billing.view/manage, modules.manage, entitlements.manage, coupons.manage, affiliates.manage, announcements.manage, audit.view, settings.manage). `is_platform_admin` is an explicit bypass; all actions audited.

Pages: dashboard (tenants active/trial/suspended, subs, MRR/ARR, revenue, plan distribution, latest tenants/subs/failed payments), tenants index/show (company, owner, status, plan/sub, usage, modules, branches/warehouses/users, actions activate/suspend/archive/change-plan/extend/impersonate), plans (CRUD + entitlement matrix boolean/numeric), modules (version/status/deps/tenants/health), audit, health (PHP/Laravel/DB/cache/queue/storage/failed-jobs/modules, no secrets), billing/subscriptions/coupons/affiliates/announcements/settings (API-backed, UI incremental).

Impersonation: `impersonation_sessions (platform_user_id, tenant_id, target_user_id, started_at, ended_at, ip, user_agent)` + session banner `Impersonating: X` + Exit. Dangerous platform changes blocked while impersonating (session flag checked before platform writes).
