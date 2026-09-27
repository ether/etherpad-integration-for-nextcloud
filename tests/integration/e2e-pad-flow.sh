#!/usr/bin/env bash
set -euo pipefail

# Usage:
# NC_BASE_URL="https://cloud.example.tld" \
# NC_USER="alice" \
# NC_APP_PASSWORD="app-password" \
# ./tests/integration/e2e-pad-flow.sh "/Apps/Test/demo-pad"

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

echo "[1/3] CREATE ${INPUT_PATH}"
CREATE_JSON=$(nc_request POST "$API_BASE" --data-urlencode "file=${INPUT_PATH}" --data-urlencode "accessMode=protected")
echo "$CREATE_JSON"

echo "[2/3] OPEN ${INPUT_PATH}"
OPEN1_JSON=$(nc_request POST "$API_BASE/open" --data-urlencode "file=${INPUT_PATH}")
echo "$OPEN1_JSON"

echo "[3/3] OPEN again ${INPUT_PATH}"
OPEN2_JSON=$(nc_request POST "$API_BASE/open" --data-urlencode "file=${INPUT_PATH}")
echo "$OPEN2_JSON"

echo "E2E flow completed successfully."
