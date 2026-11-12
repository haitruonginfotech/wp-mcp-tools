#!/usr/bin/env bash
# Sign a release zip: writes <zip>.sig (base64 RSA-SHA256). Key from env WPMCP_SIGNING_KEY (PEM text) or file $2.
#   WPMCP_SIGNING_KEY="$(cat release-private.pem)" bash bin/sign-release.sh wp-mcp-tools-1.2.3.zip
set -euo pipefail
zip="${1:?usage: sign-release.sh file.zip [private-key.pem]}"
tmp=""
if [ -n "${2:-}" ]; then key="$2"; else
  [ -n "${WPMCP_SIGNING_KEY:-}" ] || { echo "WPMCP_SIGNING_KEY is empty" >&2; exit 1; }
  tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT; umask 077; printf '%s\n' "$WPMCP_SIGNING_KEY" > "$tmp"; key="$tmp"
fi
openssl dgst -sha256 -sign "$key" "$zip" | openssl base64 -A > "$zip.sig"
echo "signed: $zip.sig"
