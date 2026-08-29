#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Copy the working tree onto an installed app directory, for a test
# instance between releases.
#
# The file set is scripts/app-file-excludes.sh — the same definition the
# release tarball and the Docker e2e stack use. A test server that
# carries files users never receive is testing a tree nobody runs, and
# this one used to ship src/, dist/, .github/, package.json and the build
# config into a live app directory.
#
#   DEPLOY_SSH_TARGET=user@host \
#   DEPLOY_APP_PATH=/var/www/virtual/user/html/apps/etherpad_nextcloud \
#   ./scripts/deploy-rsync.sh
#
# RSYNC_DELETE=1 also removes what the app no longer ships — needed once
# after this list tightened, since rsync leaves unknown files alone.
# DRY_RUN=1 prints the changes without making them.
set -euo pipefail

# -P so the tree is resolved physically: invoked through a symlink in
# PATH, a logical `cd ..` would climb out of the symlink's directory
# instead of the checkout, and the excludes sourced below would come from
# somewhere else than the files being copied.
SCRIPT_PATH="${BASH_SOURCE[0]}"
# Bounded: a symlink cycle would otherwise spin here forever, and errexit
# cannot help because nothing fails.
for _ in $(seq 1 40); do
	[[ -L "$SCRIPT_PATH" ]] || break
	link_target="$(readlink "$SCRIPT_PATH")"
	if [[ "$link_target" == /* ]]; then
		SCRIPT_PATH="$link_target"
	else
		SCRIPT_PATH="$(cd -P "$(dirname "$SCRIPT_PATH")" && pwd)/$link_target"
	fi
done
if [[ -L "$SCRIPT_PATH" ]]; then
	echo "Could not resolve ${BASH_SOURCE[0]} to a real file — symlink loop?" >&2
	exit 1
fi
ROOT_DIR="$(cd -P "$(dirname "$SCRIPT_PATH")/.." && pwd)"

: "${DEPLOY_SSH_TARGET:?DEPLOY_SSH_TARGET is required (example: user@nextcloud.example.com)}"
: "${DEPLOY_APP_PATH:?DEPLOY_APP_PATH is required (example: /var/www/nextcloud/apps/etherpad_nextcloud)}"

source "${ROOT_DIR}/scripts/app-file-excludes.sh"

RSYNC_ARGS=(-az --human-readable --itemize-changes "${RSYNC_EXCLUDES[@]}")

if [[ "${RSYNC_DELETE:-0}" == "1" ]]; then
	RSYNC_ARGS+=(--delete)
fi
if [[ "${DRY_RUN:-0}" == "1" ]]; then
	RSYNC_ARGS+=(--dry-run)
	echo "DRY RUN — nothing is written."
fi

echo "Deploying ${ROOT_DIR} -> ${DEPLOY_SSH_TARGET}:${DEPLOY_APP_PATH}"
rsync "${RSYNC_ARGS[@]}" "${ROOT_DIR}/" "${DEPLOY_SSH_TARGET}:${DEPLOY_APP_PATH}/"

echo "Deploy finished."
