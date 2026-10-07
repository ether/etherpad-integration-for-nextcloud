#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

require_command() {
	local cmd="$1"
	if ! command -v "$cmd" >/dev/null 2>&1; then
		echo "Missing required command: $cmd" >&2
		exit 2
	fi
}

echo "[1/3] Preconditions"
require_command git
require_command php

if [[ "${ALLOW_DIRTY_WORKTREE:-0}" != "1" ]]; then
	if [[ -n "$(git -C "$ROOT_DIR" status --porcelain)" ]]; then
		echo "Working tree is dirty. Commit or stash changes first, or set ALLOW_DIRTY_WORKTREE=1." >&2
		exit 2
	fi
fi

echo "[2/3] Unit checks"
cd "$ROOT_DIR"
# The standalone path-normalizer script this used to run was folded into the
# PHPUnit suite in #41 (PathNormalizerTest). The call outlived it and, under
# set -e, aborted the whole check — so this gate did not complete for two
# releases.
#
# PHPUnit is required rather than optional. It was the second of two test
# paths when the standalone script existed; as the only one, skipping it
# would let this gate report "local-only checks passed" having run nothing
# at all — which is worse than the abort it replaced.
if [[ ! -x "${ROOT_DIR}/vendor/bin/phpunit" ]]; then
	echo "PHPUnit is missing and this check runs no tests without it." >&2
	echo "Install it once with: composer install --no-interaction" >&2
	exit 2
fi
"${ROOT_DIR}/vendor/bin/phpunit" --testsuite unit

# The end-to-end checks are the Playwright suite. The shell scripts this
# used to run against an instance in NC_BASE_URL were folded into it, and
# CI runs it on every pull request that touches the app.
echo "[3/3] Done (unit checks passed)."
echo "-> End-to-end: tests/e2e/docker/run-suite.sh against the container stack,"
echo "   or npm run test:e2e against the instance in tests/e2e/.env.e2e."
