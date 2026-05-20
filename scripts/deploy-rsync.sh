#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

: "${DEPLOY_SSH_TARGET:?DEPLOY_SSH_TARGET is required (example: user@nextcloud.example.com)}"
: "${DEPLOY_APP_PATH:?DEPLOY_APP_PATH is required (example: /var/www/nextcloud/apps/etherpad_nextcloud)}"

RSYNC_DELETE="${RSYNC_DELETE:-0}"

RSYNC_ARGS=(
	-az
	--human-readable
	--itemize-changes
	--exclude=".git/"
	--exclude="node_modules/"
	--exclude="vendor/"
	--exclude="tests/"
	--exclude="docs/"
	--exclude=".phpunit.cache/"
	--exclude="_copy_probe/"
	--exclude=".DS_Store"
	--exclude="ToDo.md"
	--exclude="*.zip"
)

if [[ "$RSYNC_DELETE" == "1" ]]; then
	RSYNC_ARGS+=(--delete)
fi

echo "Deploying ${ROOT_DIR} -> ${DEPLOY_SSH_TARGET}:${DEPLOY_APP_PATH}"
rsync "${RSYNC_ARGS[@]}" "${ROOT_DIR}/" "${DEPLOY_SSH_TARGET}:${DEPLOY_APP_PATH}/"

echo "Deploy finished."
