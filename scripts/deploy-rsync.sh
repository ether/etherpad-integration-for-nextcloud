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

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

: "${DEPLOY_SSH_TARGET:?DEPLOY_SSH_TARGET is required (example: user@nextcloud.example.com)}"
: "${DEPLOY_APP_PATH:?DEPLOY_APP_PATH is required (example: /var/www/nextcloud/apps/etherpad_nextcloud)}"

# Through ROOT_DIR, which is canonicalised: invoked via a symlink, a
# second $(dirname ...) can name a different checkout than the one being
# copied below.
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
