#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Mint a throwaway CA plus one SAN certificate covering both stack
# hostnames. Deterministic files beat Caddy's internal PKI here: the CA
# has to be trusted in three places (the Nextcloud container, node's
# fetch, Chromium's NSS store), and all three want the certificate to
# exist before anything starts.
#
# Everything lands in tests/e2e/docker/certs/, which is gitignored.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
certs="$here/certs"
mkdir -p "$certs"

# All four, not just the certificates: a half-removed set would otherwise
# be reported as present and fail later inside Caddy, far from the cause.
if [[ -f "$certs/ca.crt" && -f "$certs/ca.key" && -f "$certs/site.crt" && -f "$certs/site.key" && "${FORCE:-0}" != "1" ]]; then
	echo "certs already present in $certs (FORCE=1 to regenerate)"
	exit 0
fi

# 1. CA
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
	-keyout "$certs/ca.key" -out "$certs/ca.crt" \
	-subj "/CN=etherpad-nextcloud e2e local CA" \
	-addext "basicConstraints=critical,CA:TRUE" \
	-addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null

# 2. Leaf for both hostnames
openssl req -newkey rsa:2048 -nodes -sha256 \
	-keyout "$certs/site.key" -out "$certs/site.csr" \
	-subj "/CN=nc.pad.test" 2>/dev/null

cat > "$certs/site.ext" <<'EXT'
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:nc.pad.test,DNS:ep.pad.test
EXT

openssl x509 -req -in "$certs/site.csr" -CA "$certs/ca.crt" -CAkey "$certs/ca.key" \
	-CAcreateserial -out "$certs/site.crt" -days 3650 -sha256 \
	-extfile "$certs/site.ext" 2>/dev/null

rm -f "$certs/site.csr" "$certs/site.ext"
chmod 644 "$certs"/*.crt "$certs"/*.key

echo "wrote CA and certificate to $certs"
