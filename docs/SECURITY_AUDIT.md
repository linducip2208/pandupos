# Security audit evidence

## 2026-09-18: repository secret scan

Scope: all tracked repository files, excluding the scanner itself. The CI job
executes `bash scripts/scan-secrets.sh` on every push and pull request. It
fails on common cloud access keys, private-key blocks, payment-style secret
keys, Slack tokens, GitHub tokens, and browser API keys.

Result: PASS locally on 2026-09-18. `composer audit` also reported no known
dependency advisories. Production seeding refuses the documented default
platform-admin password unless `PLATFORM_ADMIN_PASSWORD` is explicitly set.

This is not a substitute for deployment secret management, a full web/API
penetration test, or the separate third-party license audit. Those gates stay
unverified until their own evidence exists.
