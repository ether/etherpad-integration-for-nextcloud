#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Copy the app from the working tree into the running stack. Called by
# up.sh, and useful on its own after editing PHP or rebuilding js/.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
app=/var/www/html/custom_apps/etherpad_nextcloud

compose() { docker compose -f "$here/compose.yml" "$@"; }

compose exec -T -u www-data nextcloud sh -c "rm -rf $app && mkdir -p $app"
# Only what the app actually ships — node_modules, .git, vendor and the
# Playwright artefacts stay out of custom_apps.
for path in appinfo lib js css img l10n templates LICENSE; do
	[[ -e "$repo/$path" ]] || continue
	compose cp "$repo/$path" "nextcloud:$app/"
done
compose exec -T -u root nextcloud chown -R www-data:www-data "$app"
