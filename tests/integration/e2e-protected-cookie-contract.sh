#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# EXPECTED_SAMESITE="Lax" \
# ./tests/integration/e2e-protected-cookie-contract.sh "/Apps/Test/cookie-contract"

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

json_field() {
	local json="$1"
	local key="$2"
	printf '%s' "$json" | php -r '
		$data = json_decode(stream_get_contents(STDIN), true);
		$key = $argv[1];
		if (!is_array($data) || !array_key_exists($key, $data)) {
			exit(2);
		}
		$value = $data[$key];
		if (is_bool($value)) {
			echo $value ? "true" : "false";
			exit(0);
		}
		if (is_scalar($value)) {
			echo (string)$value;
			exit(0);
		}
		exit(3);
	' "$key"
}

extract_status_code() {
	local headers_file="$1"
	awk '/^HTTP\// { code=$2 } END { print code }' "$headers_file"
}

assert_cookie_contains() {
	local cookie_line="$1"
	local needle="$2"
	local label="$3"
	local lower
	lower="$(printf '%s' "$cookie_line" | tr '[:upper:]' '[:lower:]')"
	if [[ "$lower" != *"$needle"* ]]; then
		echo "Cookie assertion failed: missing ${label} in '${cookie_line}'" >&2
		exit 1
	fi
}

assert_cookie_not_contains() {
	local cookie_line="$1"
	local needle="$2"
	local label="$3"
	local lower
	lower="$(printf '%s' "$cookie_line" | tr '[:upper:]' '[:lower:]')"
	if [[ "$lower" == *"$needle"* ]]; then
		echo "Cookie assertion failed: unexpected ${label} in '${cookie_line}'" >&2
		exit 1
	fi
}

nc_init_auth

TMP_HEADERS="$(mktemp)"
TMP_BODY="$(mktemp)"
cleanup() {
	set +e
	if [[ -f "${TMP_HEADERS:-}" ]]; then
		rm -f "$TMP_HEADERS"
	fi
	if [[ -f "${TMP_BODY:-}" ]]; then
		rm -f "$TMP_BODY"
	fi
	nc_cleanup_auth
}
trap cleanup EXIT

echo "[1/4] CREATE protected pad ${INPUT_PATH}"
CREATE_JSON="$(nc_request POST "$API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")"
FILE_ID="$(json_field "$CREATE_JSON" "file_id")"
if ! [[ "$FILE_ID" =~ ^[0-9]+$ ]] || [[ "$FILE_ID" -le 0 ]]; then
	echo "Invalid file_id from create response: ${FILE_ID}" >&2
	echo "$CREATE_JSON" >&2
	exit 1
fi

echo "[2/4] OPEN by id and inspect session cookie"
nc_refresh_csrf
curl -sS -u "$NC_AUTH" -b "$NC_CSRF_COOKIE_JAR" -c "$NC_CSRF_COOKIE_JAR" \
	-X POST \
	-H "requesttoken: ${NC_CSRF_TOKEN}" \
	--data-urlencode "fileId=${FILE_ID}" \
	-D "$TMP_HEADERS" -o "$TMP_BODY" \
	"${API_BASE}/open-by-id"

HTTP_CODE="$(extract_status_code "$TMP_HEADERS")"
if [[ "$HTTP_CODE" != "200" ]]; then
	echo "open-by-id failed: HTTP ${HTTP_CODE}" >&2
	cat "$TMP_BODY" >&2
	exit 1
fi

OPEN_JSON="$(cat "$TMP_BODY")"
ACCESS_MODE="$(json_field "$OPEN_JSON" "access_mode")"
if [[ "$ACCESS_MODE" != "protected" ]]; then
	echo "Unexpected access_mode from open-by-id: ${ACCESS_MODE}" >&2
	echo "$OPEN_JSON" >&2
	exit 1
fi

SESSION_COOKIE_LINES="$(grep -i '^Set-Cookie:[[:space:]]*sessionID=' "$TMP_HEADERS" | tr -d '\r' || true)"
SESSION_COOKIE_COUNT="$(printf '%s\n' "$SESSION_COOKIE_LINES" | sed '/^$/d' | wc -l | tr -d ' ')"
if [[ "$SESSION_COOKIE_COUNT" != "1" ]]; then
	echo "Expected exactly one sessionID Set-Cookie header, got ${SESSION_COOKIE_COUNT}" >&2
	grep -i '^Set-Cookie:' "$TMP_HEADERS" | tr -d '\r' >&2 || true
	exit 1
fi
SESSION_COOKIE_LINE="$(printf '%s\n' "$SESSION_COOKIE_LINES" | sed -n '1p')"

assert_cookie_contains "$SESSION_COOKIE_LINE" "secure" "Secure"
# Lax on a default instance. An admin can set
# etherpad_session_cookie_samesite=none for a cross-site embed behind
# proxy authentication; this check then has to be told, because the value
# is not derivable from anything the script can see.
EXPECTED_SAMESITE="${EXPECTED_SAMESITE:-Lax}"
EXPECTED_SAMESITE_LOWER="$(printf '%s' "$EXPECTED_SAMESITE" | tr '[:upper:]' '[:lower:]')"
# Checked before use: `Non` would match inside `samesite=none` and pass
# while asserting nothing.
case "$EXPECTED_SAMESITE_LOWER" in
	lax|none|strict) ;;
	*)
		echo "EXPECTED_SAMESITE must be Lax, None or Strict, got '${EXPECTED_SAMESITE}'" >&2
		exit 1
		;;
esac
assert_cookie_contains "$SESSION_COOKIE_LINE" "samesite=${EXPECTED_SAMESITE_LOWER}" "SameSite=${EXPECTED_SAMESITE}"

# HttpOnly is not a constant of this contract, it depends on the pad server.
# The major-3 boundary below restates EtherpadReleasePolicy's
# HTTP_ONLY_SINCE_MAJOR rather than importing it — a check that asked the app
# what to expect would assert nothing. Moving the boundary means moving it
# here, in the Playwright spec, and in docs/etherpad-integration.md.
# Up to Etherpad 2.7.3 the pad app reads sessionID in the browser, so the
# cookie has to stay script-readable; from 3.0.0 the server takes it out of
# the socket.io handshake. Asking /health is how the app decides, so it is
# how this checks. No api key needed for it.
PAD_URL="$(json_field "$OPEN_JSON" "pad_url" || true)"
EP_ORIGIN="$(printf '%s' "$PAD_URL" | sed -n 's#^\(https\{0,1\}://[^/]*\).*#\1#p')"
EP_RELEASE=""
if [[ -n "$EP_ORIGIN" ]]; then
	EP_RELEASE="$(curl -fsS --max-time 10 "${EP_ORIGIN}/health" 2>/dev/null \
		| sed -n 's/.*"releaseId"[[:space:]]*:[[:space:]]*"\([0-9][0-9.]*\).*/\1/p' || true)"
fi

if [[ -z "$EP_RELEASE" ]]; then
	# Not a failure: the pad server may be unreachable from here even though
	# Nextcloud can reach it. Say so rather than asserting the wrong half.
	echo "  note: could not read the Etherpad release from ${EP_ORIGIN:-<unknown>}/health; skipping the HttpOnly assertion" >&2
elif [[ "${EP_RELEASE%%.*}" -ge 3 ]]; then
	assert_cookie_contains "$SESSION_COOKIE_LINE" "httponly" "HttpOnly (Etherpad ${EP_RELEASE} reads the session server-side)"
else
	assert_cookie_not_contains "$SESSION_COOKIE_LINE" "httponly" "HttpOnly (Etherpad ${EP_RELEASE} reads the session in the browser)"
fi

echo "[3/4] CLEANUP trash ${INPUT_PATH}"
TRASH_RES="$(nc_request_with_code POST "$API_BASE/trash" --data-urlencode "file=${INPUT_PATH}")"
TRASH_CODE="$(printf '%s' "$TRASH_RES" | tail -n1)"
if [[ "$TRASH_CODE" != "200" ]]; then
	echo "Cleanup trash failed with HTTP ${TRASH_CODE}" >&2
	printf '%s\n' "$TRASH_RES" | sed '$d' >&2
	exit 1
fi

echo "[4/4] PASS protected cookie contract"
echo "Cookie: ${SESSION_COOKIE_LINE}"
