# Subscriptions — PanduPOS

Statuses: `trialing, pending, active, past_due, grace_period, suspended, cancelled, expired`.

Methods (`SubscriptionService`): `startTrial, subscribe, activate, renew, upgrade, downgrade, suspend, cancel, expire`. History rows are never destroyed — transitions only. Every change invalidates `tenant:{id}:entitlements` cache.

Expired/suspended/cancelled subscriptions grant no entitlements; paid features return 403.
