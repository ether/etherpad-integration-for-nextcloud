#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# Rebuild the Files FullTextSearch index for the throwaway admin account.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
env_file="$here/../.env.e2e.docker"

if [[ ! -f "$env_file" ]]; then
	echo "$env_file is missing; run FULLTEXTSEARCH=1 NC_VERSION=34 $here/up.sh first." >&2
	exit 1
fi

set -a
# shellcheck disable=SC1090
source "$env_file"
set +a

if [[ -z "${E2E_USER:-}" ]]; then
	echo "E2E_USER is missing from $env_file." >&2
	exit 1
fi

compose() { docker compose -f "$here/compose.yml" "$@"; }

if ! compose ps --services --status running | grep -qx elasticsearch; then
	echo "Elasticsearch is not running. Start a fresh NC 34 stack with FULLTEXTSEARCH=1." >&2
	exit 1
fi

compose exec -T -u www-data nextcloud php occ fulltextsearch:index "{\"user\":\"$E2E_USER\"}" --no-readline --quiet
echo "Full-text index for $E2E_USER is up to date."
