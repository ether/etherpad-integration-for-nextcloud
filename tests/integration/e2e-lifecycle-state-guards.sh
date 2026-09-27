#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-lifecycle-state-guards.sh "/codex-e2e-lifecycle"

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <pad-path-without-extension-or-with-.pad>" >&2
	exit 1
fi

: "${NC_BASE_URL:?NC_BASE_URL is required}"
: "${NC_USER:?NC_USER is required}"
: "${NC_APP_PASSWORD:?NC_APP_PASSWORD is required}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tests/integration/lib-nextcloud-auth.sh
source "${SCRIPT_DIR}/lib-nextcloud-auth.sh"

INPUT_PATH="$1"
if [[ "$INPUT_PATH" != *.pad ]]; then
	INPUT_PATH="${INPUT_PATH}.pad"
fi

API_BASE="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/pads"
nc_init_auth
trap 'nc_cleanup_auth' EXIT

request_with_code() {
	local method="$1"
	local url="$2"
	shift 2
	nc_request_with_code "$method" "$url" "$@"
}

assert_status_code() {
	local label="$1"
	local expected="$2"
	local response="$3"
	local http_code
	http_code="$(printf '%s' "$response" | tail -n1)"
	local body
	body="$(printf '%s' "$response" | sed '$d')"
	echo "[${label}] HTTP ${http_code}"
	echo "$body"
	if [[ "$http_code" -ne "$expected" ]]; then
		echo "Expected HTTP ${expected} for ${label}, got ${http_code}" >&2
		exit 1
	fi
}

assert_body_contains() {
	local label="$1"
	local needle="$2"
	local response="$3"
	local body
	body="$(printf '%s' "$response" | sed '$d')"
	if ! printf '%s' "$body" | rg -q "$needle"; then
		echo "Expected ${label} response body to contain: ${needle}" >&2
		exit 1
	fi
}

echo "[1/5] CREATE ${INPUT_PATH}"
CREATE_RES="$(request_with_code POST "$API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")"
assert_status_code "create" 200 "$CREATE_RES"

echo "[2/5] RESTORE on active pad (must be skipped)"
RESTORE_ACTIVE_RES="$(request_with_code POST "$API_BASE/restore" --data-urlencode "file=${INPUT_PATH}")"
assert_status_code "restore-active" 409 "$RESTORE_ACTIVE_RES"
assert_body_contains "restore-active" "\"status\":\"skipped\"" "$RESTORE_ACTIVE_RES"
assert_body_contains "restore-active" "pad_present" "$RESTORE_ACTIVE_RES"

echo "[3/5] TRASH active pad"
TRASH_RES="$(request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
assert_status_code "trash-active" 200 "$TRASH_RES"
assert_body_contains "trash-active" "\"status\":\"trashed\"" "$TRASH_RES"

echo "[4/5] TRASH again (must be skipped)"
TRASH_AGAIN_RES="$(request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
assert_status_code "trash-again" 409 "$TRASH_AGAIN_RES"
assert_body_contains "trash-again" "\"status\":\"skipped\"" "$TRASH_AGAIN_RES"
assert_body_contains "trash-again" "binding_not_found" "$TRASH_AGAIN_RES"

echo "[5/5] RESTORE trashed pad"
RESTORE_RES="$(request_with_code POST "$API_BASE/restore" --data-urlencode "file=${INPUT_PATH}")"
assert_status_code "restore-trashed" 200 "$RESTORE_RES"
assert_body_contains "restore-trashed" "\"status\":\"restored\"" "$RESTORE_RES"

echo "Lifecycle state guard checks passed."
