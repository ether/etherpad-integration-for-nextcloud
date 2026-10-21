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
#
# APP_SOURCE names an extracted release to copy instead, as it is: its
# file set is the one its users have (test-upgrade.sh).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
app=/var/www/html/custom_apps/etherpad_nextcloud

source "$repo/scripts/app-file-excludes.sh"

compose() { docker compose -f "$here/compose.yml" "$@"; }

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
if [[ -n "${APP_SOURCE:-}" ]]; then
	if [[ ! -f "$APP_SOURCE/appinfo/info.xml" ]]; then
		echo "APP_SOURCE is no copy of the app, it has no appinfo/info.xml: $APP_SOURCE" >&2
		exit 1
	fi
	# Said every time: a variable left in a shell would otherwise put an
	# old copy in the stack, and the suite would test it without a sign.
	echo "Copying the app from APP_SOURCE, not the working tree: $APP_SOURCE"
	rsync -a "$APP_SOURCE/" "$staging/app/"
else
	rsync -a "${RSYNC_EXCLUDES[@]}" "$repo/" "$staging/app/"
fi

compose exec -T -u www-data nextcloud sh -c "rm -rf $app && mkdir -p $app"
compose cp "$staging/app/." "nextcloud:$app/"
compose exec -T -u root nextcloud chown -R www-data:www-data "$app"

# The Nextcloud image caches bytecode with opcache.revalidate_freq=60, so
# without this the stack keeps running the previous code for up to a
# minute — a suite started right after an edit would test the old app and
# report a pass.
compose restart nextcloud >/dev/null
compose exec -T -u www-data nextcloud sh -c 'for _ in $(seq 1 60); do php occ status 2>/dev/null | grep -q "installed: true" && exit 0; sleep 1; done; exit 1'

# An enabled app is upgraded on its own, as a release upgrades it - its
# migrations, repair steps and jobs - wherever Nextcloud would ask for it:
# info.xml names another version than the one installed, after a release
# is set or when the tree comes from another branch. And where a migration
# has not run, which a branch adds without moving the version. Not through
# `occ upgrade`, which upgrades every app the app store has a newer release
# of, and so would move the apps this stack pins. The migrations are asked
# as a set, not for the next one after the last run: a stack that ran
# another branch has run migrations this tree does not know. On a new
# stack up.sh enables the app after this, which runs them all.
as_www() { compose exec -T -u www-data nextcloud "$@"; }
why="$(as_www php -r '
	require "lib/base.php";
	$app = "etherpad_nextcloud";
	$ms = new \OC\DB\MigrationService($app, \OCP\Server::get(\OC\DB\Connection::class));
	$pending = array_diff($ms->getAvailableVersions(), $ms->getMigratedVersions());
	$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
	if ($pending !== []) {
		echo "migrations " . implode(",", $pending);
	} elseif ($appManager->isUpgradeRequired($app)) {
		echo "version " . \OCP\Server::get(\OCP\IAppConfig::class)->getValueString($app, "installed_version") . " -> " . $appManager->getAppVersion($app, false);
	}
')"
if [ "$(as_www php occ config:app:get etherpad_nextcloud enabled 2>/dev/null)" = yes ] && [ -n "$why" ]; then
	echo "Upgrading the app for its $why"
	as_www php < "$here/update-app.php"
fi
