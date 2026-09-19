#!/usr/bin/env bash

# PanduPOS secret + credential scan. Tracked files only; runtime secrets
# belong in env/secret manager and must never enter a commit.
set -euo pipefail

# Generic credential keys are only suspicious when they carry a non-empty
# assignment. This keeps the scanner useful in security documentation that
# names keys as examples, while still detecting actual key/value exposure.
pattern="AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|sk_(live|test)_[[:alnum:]_]+|xox[baprs]-[[:alnum:]-]+|gh[pousr]_[[:alnum:]_]+|AIza[[:alnum:]_-]{20,}|(aws_secret_access_key|database_url|db_password)[[:space:]]*[:=][[:space:]]*[^[:space:]\\\`\\\"']+"
matches="$(git grep -nEI "$pattern" -- ':!scripts/scan-secrets.sh' ':!.env.example' || true)"

fail=0
if [[ -n "$matches" ]]; then
    echo 'Potential committed secret detected:' >&2
    echo "$matches" >&2
    fail=1
fi

# .env / dumps / keys must never be tracked (allow .env.example only).
tracked_env="$(git ls-files | grep -E '(^|/)\.env$|\.sql$|\.dump$|\.pem$|\.key$|database\.sqlite$' || true)"
if [[ -n "$tracked_env" ]]; then
    echo 'Forbidden tracked file (env/dump/key material):' >&2
    echo "$tracked_env" >&2
    fail=1
fi

# .env.example must not contain real secrets (only placeholders).
if git ls-files | grep -qx '.env.example'; then
    if grep -nEI 'sk_live_|AKIA[0-9A-Z]{16}|-----BEGIN .*PRIVATE KEY-----' .env.example; then
        echo '.env.example contains real-looking secret material.' >&2
        fail=1
    fi
fi

if [[ "$fail" -ne 0 ]]; then
    exit 1
fi

echo 'Secret scan passed: no supported credential signature was found in tracked files.'
