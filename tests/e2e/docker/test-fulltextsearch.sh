#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Focused integration check for the optional NC 34 full-text-search stack.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ca_file="$here/certs/ca.crt"

source "$here/stack-env.sh"
require_stack_env E2E_BASE_URL E2E_USER E2E_APP_PASSWORD E2E_ETHERPAD_URL E2E_ETHERPAD_API_KEY
require_elasticsearch

tmp="$(mktemp -d)"
suffix="${GITHUB_RUN_ID:-local}-$$-$(date +%s)"
suffix="${suffix//[^A-Za-z0-9-]/-}"
file_name="e2e-fulltextsearch-${suffix}.pad"
plain_marker="plainsearch${RANDOM}$(date +%s)"
html_marker="htmlonly${RANDOM}$(date +%s)"
created=0

urlencode() {
	python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"
}

dav_url="$E2E_BASE_URL/remote.php/dav/files/$(urlencode "$E2E_USER")/$(urlencode "$file_name")"
trash_url="$E2E_BASE_URL/remote.php/dav/trashbin/$(urlencode "$E2E_USER")/trash"

cleanup() {
	set +e
	if [[ "$created" == 1 ]]; then
		curl --silent --show-error --cacert "$ca_file" \
			--user "$E2E_USER:$E2E_APP_PASSWORD" --request DELETE "$dav_url" >/dev/null
		curl --silent --show-error --cacert "$ca_file" \
			--user "$E2E_USER:$E2E_APP_PASSWORD" --request PROPFIND \
			--header 'Depth: 1' "$trash_url" > "$tmp/trash.xml"
		trash_entry="$(python3 - "$tmp/trash.xml" "$file_name" <<'PY'
import sys
import urllib.parse
import xml.etree.ElementTree as ET

root = ET.parse(sys.argv[1]).getroot()
prefix = sys.argv[2] + '.d'
for element in root.findall('.//{DAV:}href'):
    href = element.text or ''
    name = urllib.parse.unquote(href).rstrip('/').rsplit('/', 1)[-1]
    if name.startswith(prefix):
        print(href)
        break
PY
)"
		if [[ -n "$trash_entry" ]]; then
			curl --silent --show-error --cacert "$ca_file" \
				--user "$E2E_USER:$E2E_APP_PASSWORD" --request DELETE \
				"$E2E_BASE_URL$trash_entry" >/dev/null
		fi
		"$here/index-fulltextsearch.sh" >/dev/null
	fi
	rm -rf "$tmp"
}
trap cleanup EXIT

echo "==> creating $file_name"
created_json="$(curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--user "$E2E_USER:$E2E_APP_PASSWORD" --request POST \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	--data-urlencode "file=/$file_name" \
	--data-urlencode 'accessMode=public' \
	"$E2E_BASE_URL/index.php/apps/etherpad_nextcloud/api/v1/pads")"
created=1
printf '%s' "$created_json" > "$tmp/created.json"

# One interpreter, so both values provably come from the same parse.
{
	read -r file_id
	read -r pad_id
} < <(python3 - "$tmp/created.json" <<'PY'
import json
import sys

data = json.load(open(sys.argv[1], encoding='utf-8'))
print(data['file_id'])
print(data['pad_id'])
PY
)
if [[ ! "$file_id" =~ ^[1-9][0-9]*$ || ! "$pad_id" =~ ^nc-[a-z0-9]{24}$ ]]; then
	echo "Pad create response did not contain a valid file_id and pad_id: $created_json" >&2
	exit 1
fi
frontmatter_marker="${pad_id#nc-}"

echo "==> writing and synchronising the Etherpad content"
etherpad_json="$(curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--request POST \
	--data-urlencode "apikey=$E2E_ETHERPAD_API_KEY" \
	--data-urlencode "padID=$pad_id" \
	--data-urlencode "text=$plain_marker" \
	"$E2E_ETHERPAD_URL/api/1.2.15/setText")"
printf '%s' "$etherpad_json" > "$tmp/etherpad.json"
python3 - "$tmp/etherpad.json" <<'PY'
import json
import sys

data = json.load(open(sys.argv[1], encoding='utf-8'))
if data.get('code') != 0:
    raise SystemExit(f"Etherpad setText failed: {data}")
PY

curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--user "$E2E_USER:$E2E_APP_PASSWORD" --request POST \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	"$E2E_BASE_URL/index.php/apps/etherpad_nextcloud/api/v1/pads/sync/$file_id?force=1" \
	> "$tmp/sync.json"

# Make the HTML exclusion observable without changing the visible pad text.
curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--user "$E2E_USER:$E2E_APP_PASSWORD" "$dav_url" > "$tmp/pad"
python3 - "$tmp/pad" "$html_marker" <<'PY'
import sys

path, marker = sys.argv[1:]
content = open(path, encoding='utf-8').read()
needle = '\n[HTML-END]'
if content.count(needle) != 1:
    raise SystemExit('Synced pad did not contain exactly one HTML end marker.')
content = content.replace(needle, f'\n<!-- {marker} -->{needle}', 1)
open(path, 'w', encoding='utf-8').write(content)
PY
curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--user "$E2E_USER:$E2E_APP_PASSWORD" --request PUT \
	--header 'Content-Type: application/x-etherpad-nextcloud' \
	--data-binary "@$tmp/pad" "$dav_url" >/dev/null

echo "==> indexing and checking the search contract"
"$here/index-fulltextsearch.sh"

search() {
	occ fulltextsearch:search --output=json "$E2E_USER" "$1"
}

# Elasticsearch refreshes on its own schedule - a second by default - and
# fulltextsearch_elasticsearch neither forces a refresh nor waits for one.
# Searching straight after indexing therefore races it, so every assertion
# about a document this run just wrote has to be retried.
wait_for() {
	local marker="$1" expected="$2" out="$3" attempt
	for attempt in $(seq 1 20); do
		# Only a search that ran says anything. Without this, an occ or
		# Elasticsearch failure leaves $out empty, and "gone" would be
		# satisfied by never having looked.
		if search "$marker" > "$out"; then
			if grep -q "$file_name" "$out" 2>/dev/null; then
				if [[ "$expected" == found ]]; then
					return 0
				fi
			elif [[ "$expected" == gone ]]; then
				return 0
			fi
		fi
		sleep 1
	done

	echo "Searching for $marker did not settle on \"$expected\" within 20 tries." >&2
	cat "$out" >&2
	exit 1
}

wait_for "$plain_marker" found "$tmp/plain.json"
search "$html_marker" > "$tmp/html.json"
search "$frontmatter_marker" > "$tmp/frontmatter.json"

python3 - "$tmp/plain.json" "$tmp/html.json" "$tmp/frontmatter.json" "$file_name" <<'PY'
import json
import sys

plain, html, frontmatter = [json.load(open(path, encoding='utf-8')) for path in sys.argv[1:4]]
file_name = sys.argv[4]
matches = [item for item in plain.get('files', []) if item.get('title') == file_name]
if len(matches) != 1:
    raise SystemExit(f'Expected exactly one plain-text result for {file_name}, got: {plain}')
icon = matches[0].get('info', {}).get('unified', {}).get('icon', '')
# Search results carry no preview, so without the result listener this would
# be whatever the MIME alias resolves to inside Nextcloud.
if 'etherpad_nextcloud/img/filetypes/etherpad-nextcloud-pad.svg' not in icon:
    raise SystemExit(f'Expected the pad icon served from the app, got: {icon!r}')
if any(item.get('title') == file_name for item in html.get('files', [])):
    raise SystemExit('The HTML-only marker was indexed.')
if any(item.get('title') == file_name for item in frontmatter.get('files', [])):
    raise SystemExit('The unique part of the frontmatter pad id was indexed.')
PY

echo "Full-text search found only the plain snapshot, with the pad icon served from the app."

echo "==> checking that a newer snapshot replaces what was indexed"
second_marker="secondsearch${RANDOM}$(date +%s)"
curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--request POST \
	--data-urlencode "apikey=$E2E_ETHERPAD_API_KEY" \
	--data-urlencode "padID=$pad_id" \
	--data-urlencode "text=$second_marker" \
	"$E2E_ETHERPAD_URL/api/1.2.15/setText" > "$tmp/etherpad-second.json"
python3 - "$tmp/etherpad-second.json" <<'CHECK'
import json
import sys

data = json.load(open(sys.argv[1], encoding='utf-8'))
if data.get('code') != 0:
    raise SystemExit(f"Etherpad setText failed: {data}")
CHECK
curl --silent --show-error --fail-with-body --cacert "$ca_file" \
	--user "$E2E_USER:$E2E_APP_PASSWORD" --request POST \
	--header 'Accept: application/json' \
	--header 'OCS-APIRequest: true' \
	"$E2E_BASE_URL/index.php/apps/etherpad_nextcloud/api/v1/pads/sync/$file_id?force=1" \
	> "$tmp/sync-second.json"
"$here/index-fulltextsearch.sh"
wait_for "$second_marker" found "$tmp/second.json"
wait_for "$plain_marker" gone "$tmp/replaced.json"
echo "The newer snapshot is searchable and the text it replaced is not."
