#!/usr/bin/env bash

# Scan only tracked repository content. Runtime secrets belong in the
# environment or secret manager and must never enter a commit.
set -euo pipefail

pattern='AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|sk_(live|test)_[[:alnum:]_]+|xox[baprs]-[[:alnum:]-]+|gh[pousr]_[[:alnum:]_]+|AIza[[:alnum:]_-]{20,}'
matches="$(git grep -nEI "$pattern" -- ':!scripts/scan-secrets.sh' || true)"

if [[ -n "$matches" ]]; then
    echo 'Potential committed secret detected:' >&2
    echo "$matches" >&2
    exit 1
fi

echo 'Secret scan passed: no supported credential signature was found in tracked files.'
