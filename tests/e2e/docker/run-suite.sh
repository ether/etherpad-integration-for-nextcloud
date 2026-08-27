#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Run the Playwright suite against the Docker stack from up.sh. Wraps the
# three variables that target needs so nobody has to remember them:
# the generated env file, the CA for node's fetch, and the browser-side
# certificate exemption for the per-run self-signed CA.
set -euo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$repo"

if [[ ! -f tests/e2e/.env.e2e.docker ]]; then
	echo "tests/e2e/.env.e2e.docker is missing — run tests/e2e/docker/up.sh first." >&2
	exit 1
fi

export E2E_ENV_FILE=tests/e2e/.env.e2e.docker
export NODE_EXTRA_CA_CERTS="$repo/tests/e2e/docker/certs/ca.crt"
export E2E_IGNORE_HTTPS_ERRORS=1

exec npm run test:e2e -- "$@"
