#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Copy the app from the working tree into the running stack. Called by
# up.sh, and useful on its own after editing PHP or rebuilding js/.
#
# The file set comes from scripts/app-file-excludes.sh — the same
# definition the release tarball uses — so the stack exercises the tree
# users actually install.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
app=/var/www/html/custom_apps/etherpad_nextcloud

source "$repo/scripts/app-file-excludes.sh"

compose() { docker compose -f "$here/compose.yml" "$@"; }

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
rsync -a "${RSYNC_EXCLUDES[@]}" "$repo/" "$staging/app/"

compose exec -T -u www-data nextcloud sh -c "rm -rf $app && mkdir -p $app"
compose cp "$staging/app/." "nextcloud:$app/"
compose exec -T -u root nextcloud chown -R www-data:www-data "$app"

# The Nextcloud image caches bytecode with opcache.revalidate_freq=60, so
# without this the stack keeps running the previous code for up to a
# minute — a suite started right after an edit would test the old app and
# report a pass.
compose restart nextcloud >/dev/null
compose exec -T -u www-data nextcloud sh -c 'for _ in $(seq 1 60); do php occ status 2>/dev/null | grep -q "installed: true" && exit 0; sleep 1; done; exit 1'

# Nextcloud runs an app's migrations when info.xml's version is above the
# one it has installed, and a branch leaves the version as it is. An
# enabled app with a migration it has not run yet is upgraded on its own,
# as a release upgrades it: its migrations, repair steps and jobs. Not
# through `occ upgrade`, which upgrades every app the app store has a newer
# release of, and so would move the apps this stack pins. Asked as a set,
# not for the next one after the last run: a stack that ran another branch
# has run migrations this tree does not know. On a new stack up.sh enables
# the app after this, which runs them all.
as_www() { compose exec -T -u www-data nextcloud "$@"; }
pending="$(as_www php -r 'require "lib/base.php"; $ms = new \OC\DB\MigrationService("etherpad_nextcloud", \OCP\Server::get(\OC\DB\Connection::class)); echo implode(",", array_diff($ms->getAvailableVersions(), $ms->getMigratedVersions()));')"
if [ "$(as_www php occ config:app:get etherpad_nextcloud enabled 2>/dev/null)" = yes ] && [ -n "$pending" ]; then
	echo "Running the app's migrations: $pending"
	as_www php -r 'require "lib/base.php"; exit(\OC_App::updateApp("etherpad_nextcloud") ? 0 : 1);'
fi
