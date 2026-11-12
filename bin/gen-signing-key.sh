#!/usr/bin/env bash
# One-time: create the release signing key pair. Run it on YOUR machine, never in CI or a shared place.
#   bash bin/gen-signing-key.sh
# Writes release-private.pem (keep secret: password manager + GitHub secret WPMCP_SIGNING_KEY, never commit it)
# and includes/release-public.pem (commit it: sites use it to check every update).
set -euo pipefail
cd "$(dirname "$0")/.."
[ -e release-private.pem ] && { echo "release-private.pem already exists, refusing to overwrite." >&2; exit 1; }
openssl genrsa -out release-private.pem 3072
openssl rsa -in release-private.pem -pubout -out includes/release-public.pem
chmod 600 release-private.pem
echo
echo "1) Commit includes/release-public.pem"
echo "2) GitHub repo > Settings > Secrets and variables > Actions > New secret: WPMCP_SIGNING_KEY = whole content of release-private.pem"
echo "3) Back up release-private.pem somewhere safe, then delete it from this folder (it is git-ignored)."
