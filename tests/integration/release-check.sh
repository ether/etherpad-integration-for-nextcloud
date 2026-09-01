#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INTEGRATION_DIR="${ROOT_DIR}/tests/integration"

PATH_PREFIX="${1:-/release-check-$(date +%Y%m%d-%H%M%S)}"

require_command() {
	local cmd="$1"
	if ! command -v "$cmd" >/dev/null 2>&1; then
		echo "Missing required command: $cmd" >&2
		exit 2
	fi
}

run_e2e() {
	local script_name="$1"
	local path_suffix="$2"
	echo "-> ${script_name} ${path_suffix}"
	"${INTEGRATION_DIR}/${script_name}" "${path_suffix}"
}

has_nextcloud_e2e_env() {
	[[ -n "${NC_BASE_URL:-}" && -n "${NC_USER:-}" && -n "${NC_APP_PASSWORD:-}" ]]
}

echo "[1/5] Preconditions"
require_command git
require_command php

if [[ "${ALLOW_DIRTY_WORKTREE:-0}" != "1" ]]; then
	if [[ -n "$(git -C "$ROOT_DIR" status --porcelain)" ]]; then
		echo "Working tree is dirty. Commit or stash changes first, or set ALLOW_DIRTY_WORKTREE=1." >&2
		exit 2
	fi
fi

echo "[2/5] Unit checks"
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

if ! has_nextcloud_e2e_env; then
	echo "[3/5] Core E2E checks skipped (missing NC_BASE_URL / NC_USER / NC_APP_PASSWORD)."
	echo "[4/5] Failure-path E2E checks skipped."
	echo "[5/5] Done (local-only checks passed)."
	exit 0
fi

echo "[3/5] Core E2E checks"
run_e2e "e2e-pad-flow.sh" "${PATH_PREFIX}-pad-flow"
run_e2e "e2e-protected-cookie-contract.sh" "${PATH_PREFIX}-protected-cookie"
run_e2e "e2e-pad-copy-behavior.sh" "${PATH_PREFIX}-pad-copy"
run_e2e "e2e-lifecycle-state-guards.sh" "${PATH_PREFIX}-lifecycle"
run_e2e "e2e-public-share-folder.sh" "${PATH_PREFIX}-public-folder"
run_e2e "e2e-public-share-single-file.sh" "${PATH_PREFIX}-public-single"
run_e2e "e2e-external-url-security.sh" "${PATH_PREFIX}-external-security"
if [[ "${RUN_DEBUG_FAULT_PATHS:-0}" == "1" ]]; then
	echo "-> debug fault-path E2E checks enabled"
	run_e2e "e2e-lifecycle-trash-lock-tolerant.sh" "${PATH_PREFIX}-trash-lock"
	run_e2e "e2e-lifecycle-restore-write-failure.sh" "${PATH_PREFIX}-restore-write-failure"
else
	echo "-> debug fault-path E2E checks skipped (set RUN_DEBUG_FAULT_PATHS=1 to enable)."
fi

if [[ "${RUN_FAILURE_PATHS:-0}" != "1" ]]; then
	echo "[4/5] Failure-path E2E checks skipped (set RUN_FAILURE_PATHS=1 to enable)."
	echo "[5/5] Done."
	exit 0
fi

if [[ "${FAILURE_PATHS_PREPARED:-0}" != "1" ]]; then
	echo "[4/5] Failure-path E2E checks skipped."
	echo "-> RUN_FAILURE_PATHS=1 was set, but FAILURE_PATHS_PREPARED=1 is required."
	echo "-> Reason: these checks expect a preconfigured Etherpad outage/misconfiguration state."
	echo "[5/5] Done."
	exit 0
fi

echo "[4/5] Failure-path E2E checks"
echo "NOTE: These checks expect Etherpad outage/misconfiguration where documented."
run_e2e "e2e-sync-failure.sh" "${PATH_PREFIX}-sync-failure"
run_e2e "e2e-lifecycle-trash-failure.sh" "${PATH_PREFIX}-trash-failure"
run_e2e "e2e-lifecycle-restore-failure.sh" "${PATH_PREFIX}-restore-failure"

echo "[5/5] Done."
