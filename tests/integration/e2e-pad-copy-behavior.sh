#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-pad-copy-behavior.sh "/codex-e2e-pad-copy"

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <base-folder-path>" >&2
	exit 1
fi

: "${NC_BASE_URL:?NC_BASE_URL is required}"
: "${NC_USER:?NC_USER is required}"
: "${NC_APP_PASSWORD:?NC_APP_PASSWORD is required}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=tests/integration/lib-nextcloud-auth.sh
source "${SCRIPT_DIR}/lib-nextcloud-auth.sh"

BASE_FOLDER="$1"
RUN_ID="$(date +%s)"
FOLDER_PATH="${BASE_FOLDER%/}-${RUN_ID}"

INTERNAL_SOURCE="${FOLDER_PATH}/copy-internal-source.pad"
INTERNAL_COPY="${FOLDER_PATH}/copy-internal-copy.pad"
PUBLIC_SOURCE="${FOLDER_PATH}/copy-public-source.pad"
PUBLIC_COPY="${FOLDER_PATH}/copy-public-copy.pad"
EXTERNAL_SOURCE="${FOLDER_PATH}/copy-external-source.pad"
EXTERNAL_COPY="${FOLDER_PATH}/copy-external-copy.pad"

PADS_API="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/pads"
PUBLIC_API_BASE="${NC_BASE_URL%/}/index.php/apps/etherpad_nextcloud/api/v1/public/open"
DAV_BASE="${NC_BASE_URL%/}/remote.php/dav/files/${NC_USER}"
OCS_BASE="${NC_BASE_URL%/}/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json"

COPY_ERR_PATTERN='not linked to a managed pad|copied \.pad file|copied file without an active pad binding'

nc_init_auth
trap 'nc_cleanup_auth' EXIT

request_auth() {
	local method="$1"
	local url="$2"
	shift 2
	nc_request "$method" "$url" "$@"
}

request_auth_with_code() {
	local method="$1"
	local url="$2"
	shift 2
	nc_request_with_code "$method" "$url" "$@"
}

request_public_with_code() {
	local url="$1"
	shift
	curl -sS -X GET "$url" "$@" -w $'\n%{http_code}'
}

status_code_from_response() {
	printf '%s' "$1" | tail -n1
}

response_body() {
	printf '%s' "$1" | sed '$d'
}

assert_status_code() {
	local label="$1"
	local expected="$2"
	local response="$3"
	local code
	code="$(status_code_from_response "$response")"
	if [[ "$code" -ne "$expected" ]]; then
		echo "[${label}] Expected HTTP ${expected}, got ${code}" >&2
		echo "$(response_body "$response")" >&2
		exit 1
	fi
}

assert_body_contains() {
	local label="$1"
	local pattern="$2"
	local response="$3"
	local body
	body="$(response_body "$response")"
	if ! printf '%s' "$body" | rg -q "$pattern"; then
		echo "[${label}] Expected body pattern: ${pattern}" >&2
		echo "$body" >&2
		exit 1
	fi
}

json_get() {
	local key="$1"
	php -r '
		$body = stream_get_contents(STDIN);
		$data = json_decode($body, true);
		if (!is_array($data)) {
			fwrite(STDERR, "Invalid JSON\n");
			exit(2);
		}
		$parts = explode(".", $argv[1]);
		$cur = $data;
		foreach ($parts as $p) {
			if (!is_array($cur) || !array_key_exists($p, $cur)) {
				fwrite(STDERR, "Missing key: " . $argv[1] . "\n");
				exit(3);
			}
			$cur = $cur[$p];
		}
		if (is_scalar($cur)) {
			echo (string)$cur;
			exit(0);
		}
		echo json_encode($cur);
	' "$key"
}

dav_copy() {
	local source_path="$1"
	local destination_path="$2"
	request_auth COPY "${DAV_BASE}${source_path}" \
		-H "Destination: ${DAV_BASE}${destination_path}" \
		-H "Overwrite: F" >/dev/null
}

resolve_file_id() {
	local path="$1"
	local resolve_json
	resolve_json="$(request_auth GET "${PADS_API}/resolve" --get --data-urlencode "file=${path}")"
	printf '%s' "$resolve_json" | json_get "file_id"
}

assert_copy_is_unbound() {
	local label="$1"
	local copy_path="$2"
	local file_id="$3"

	local open_res
	open_res="$(request_auth_with_code POST "${PADS_API}/open" --data-urlencode "file=${copy_path}")"
	assert_status_code "${label}-open" 400 "$open_res"
	assert_body_contains "${label}-open" "$COPY_ERR_PATTERN" "$open_res"

	local sync_status_res
	sync_status_res="$(request_auth_with_code GET "${PADS_API}/sync-status/${file_id}")"
	assert_status_code "${label}-sync-status" 400 "$sync_status_res"
	assert_body_contains "${label}-sync-status" "$COPY_ERR_PATTERN" "$sync_status_res"

	local sync_res
	sync_res="$(request_auth_with_code POST "${PADS_API}/sync/${file_id}?force=1")"
	assert_status_code "${label}-sync" 400 "$sync_res"
	assert_body_contains "${label}-sync" "$COPY_ERR_PATTERN" "$sync_res"
}

echo "[1/9] MKCOL ${FOLDER_PATH}"
request_auth MKCOL "${DAV_BASE}${FOLDER_PATH}" >/dev/null

echo "[2/9] Internal protected source + copy"
INTERNAL_CREATE="$(request_auth POST "${PADS_API}" --data-urlencode "file=${INTERNAL_SOURCE}" --data-urlencode "accessMode=protected")"
dav_copy "${INTERNAL_SOURCE}" "${INTERNAL_COPY}"
INTERNAL_COPY_FILE_ID="$(resolve_file_id "${INTERNAL_COPY}")"
assert_copy_is_unbound "internal-copy" "${INTERNAL_COPY}" "${INTERNAL_COPY_FILE_ID}"

echo "[3/9] Internal protected source still opens"
INTERNAL_SOURCE_OPEN="$(request_auth_with_code POST "${PADS_API}/open" --data-urlencode "file=${INTERNAL_SOURCE}")"
assert_status_code "internal-source-open" 200 "$INTERNAL_SOURCE_OPEN"
assert_body_contains "internal-source-open" "\"url\":\"" "$INTERNAL_SOURCE_OPEN"

echo "[4/9] Internal public source + copy"
PUBLIC_CREATE="$(request_auth POST "${PADS_API}" --data-urlencode "file=${PUBLIC_SOURCE}" --data-urlencode "accessMode=public")"
PUBLIC_PAD_URL="$(printf '%s' "$PUBLIC_CREATE" | json_get "pad_url")"
if [[ -z "$PUBLIC_PAD_URL" ]]; then
	echo "Could not extract pad_url from public create response" >&2
	exit 1
fi
dav_copy "${PUBLIC_SOURCE}" "${PUBLIC_COPY}"
PUBLIC_COPY_FILE_ID="$(resolve_file_id "${PUBLIC_COPY}")"
assert_copy_is_unbound "public-copy" "${PUBLIC_COPY}" "${PUBLIC_COPY_FILE_ID}"

echo "[5/9] External source (from URL) + copy"
EXTERNAL_CREATE_RES="$(request_auth_with_code POST "${PADS_API}/from-url" \
	--data-urlencode "file=${EXTERNAL_SOURCE}" \
	--data-urlencode "padUrl=${PUBLIC_PAD_URL}")"
EXTERNAL_CREATE_CODE="$(status_code_from_response "${EXTERNAL_CREATE_RES}")"
if [[ "${EXTERNAL_CREATE_CODE}" == "200" ]]; then
	dav_copy "${EXTERNAL_SOURCE}" "${EXTERNAL_COPY}"
	EXTERNAL_COPY_FILE_ID="$(resolve_file_id "${EXTERNAL_COPY}")"
	assert_copy_is_unbound "external-copy" "${EXTERNAL_COPY}" "${EXTERNAL_COPY_FILE_ID}"
elif [[ "${EXTERNAL_CREATE_CODE}" == "400" ]]; then
	assert_body_contains "external-copy-create" "Invalid public pad URL|Local hosts are not allowed|Private\\\\/reserved IPs are not allowed|Only public pad URLs can be linked|not allowed for external pad sync" "${EXTERNAL_CREATE_RES}"
	echo "-> external from-url create rejected by security policy (HTTP 400); continuing with remaining checks."
else
	echo "[external-copy-create] Expected HTTP 200 or 400, got ${EXTERNAL_CREATE_CODE}" >&2
	echo "$(response_body "${EXTERNAL_CREATE_RES}")" >&2
	exit 1
fi

echo "[6/9] Share folder publicly (read-only)"
SHARE_RES="$(request_auth POST "${OCS_BASE}" -H "OCS-APIRequest: true" --data-urlencode "path=${FOLDER_PATH}" --data-urlencode "shareType=3" --data-urlencode "permissions=1")"
TOKEN="$(printf '%s' "$SHARE_RES" | json_get "ocs.data.token")"
if [[ -z "$TOKEN" ]]; then
	echo "Failed to extract public share token" >&2
	exit 1
fi

echo "[7/9] Public API must reject copied .pad in shared folder"
PUBLIC_COPY_NAME="$(basename "${INTERNAL_COPY}")"
PUBLIC_OPEN_COPY_RES="$(request_public_with_code "${PUBLIC_API_BASE}/${TOKEN}?file=/${PUBLIC_COPY_NAME}")"
assert_status_code "public-open-copy" 400 "$PUBLIC_OPEN_COPY_RES"
assert_body_contains "public-open-copy" "Pad binding is inconsistent|not linked to a managed pad|copied \\.pad file|copied file without an active pad binding" "$PUBLIC_OPEN_COPY_RES"

echo "[8/9] Public API must still open original bound .pad in same shared folder"
PUBLIC_SOURCE_NAME="$(basename "${INTERNAL_SOURCE}")"
PUBLIC_OPEN_SOURCE_RES="$(request_public_with_code "${PUBLIC_API_BASE}/${TOKEN}?file=/${PUBLIC_SOURCE_NAME}")"
assert_status_code "public-open-source" 200 "$PUBLIC_OPEN_SOURCE_RES"
assert_body_contains "public-open-source" "\"url\":\"" "$PUBLIC_OPEN_SOURCE_RES"

echo "[9/9] Done"
echo "Pad copy behavior E2E passed."
