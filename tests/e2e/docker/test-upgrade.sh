#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# The last release, as published, on a stack of its own, with pads in the
# states its users can leave them in, upgraded to this app. The test holds
# the snapshots it takes to what upgrade-fixtures.mjs expects of the
# migrations that ran, checks the pads on the new version, and runs the
# suite on the upgraded stack.
#
#   tests/e2e/docker/test-upgrade.sh
#
# FROM_VERSION is the release to upgrade from, by default the last tag
# before HEAD. Its archive is fetched from the release, or taken from
# FROM_ARCHIVE, and checked against the checksum pinned below either way.
# TO_ARCHIVE is a release archive to upgrade to instead of the working
# tree. UPGRADE_WORK is a new or empty directory for the run's files, which
# stay there; without it a temporary one keeps them only when the run
# fails. NC_VERSION and EP_VERSION are up.sh's.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"

# A project of its own, so no container or volume of the stack someone
# works in is this test's. Not taken from the shell, where a name of that
# stack would point the test at it.
export COMPOSE_PROJECT_NAME=epnc-upgrade
compose() { docker compose -f "$here/compose.yml" "$@"; }
occ() { compose exec -T -u www-data nextcloud php occ "$@"; }
update_app() { compose exec -T -u www-data nextcloud php < "$here/update-app.php"; }
# Whether version $1 reaches $2, compared at the precision $1 names: a
# moving major tag stands for the newest release of its major, which
# reaches a floor within it.
reaches() {
	local -a have want
	local i
	IFS=. read -r -a have <<< "$1"
	IFS=. read -r -a want <<< "$2"
	for i in "${!have[@]}"; do
		if (( have[i] > ${want[i]:-0} )); then
			return 0
		elif (( have[i] < ${want[i]:-0} )); then
			return 1
		fi
	done
	return 0
}

if [[ -n "$(compose ps -aq)" ]]; then
	cat >&2 <<MSG
An $COMPOSE_PROJECT_NAME stack exists already, and this test starts from a fresh one. Remove it with:

  docker compose -p $COMPOSE_PROJECT_NAME -f tests/e2e/docker/compose.yml down -v --remove-orphans
MSG
	exit 1
fi
# Every stack of compose.yml listens on this port and answers to the same
# hostnames, so only one can run.
taken="$(docker ps --filter publish=443 --format '{{.Names}}')"
if [[ -n "$taken" ]]; then
	echo "Port 443 is taken by $taken. Stop that stack first." >&2
	exit 1
fi

# up.sh's default is compose.yml's.
nc_version="${NC_VERSION:-$(sed -n 's/.*${NC_VERSION:-\([^}]*\)}.*/\1/p' "$here/compose.yml" | head -n 1)}"
if [[ ! "$nc_version" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then
	echo "NC_VERSION is '$nc_version'; the test needs a version number to hold it to the range the release runs on." >&2
	exit 1
fi

if [[ -n "${UPGRADE_WORK:-}" ]]; then
	work="$UPGRADE_WORK"
	mkdir -p "$work"
	# An earlier run's from/ or to/ would stop this one at its mkdir, and
	# its cleanup would write over that run's log.
	if [[ -n "$(ls -A "$work")" ]]; then
		echo "UPGRADE_WORK $work is not empty. Name a new or empty directory." >&2
		exit 1
	fi
else
	work="$(mktemp -d)"
fi
# up.sh writes these for the stack it brings up, over those of the stack
# someone works in, which the port check made them stop: theirs go back.
env_files=("$here/.env" "$here/../.env.e2e.docker")
saved_env="$(mktemp -d)"
for i in "${!env_files[@]}"; do
	if [[ -f "${env_files[$i]}" ]]; then
		cp -p "${env_files[$i]}" "$saved_env/$i"
	fi
done
etherpad_stopped=0
cleanup() {
	local status=$?
	# Left stopped, Etherpad fails every open on the stack kept for a look.
	if [[ "$etherpad_stopped" == 1 ]]; then
		compose start etherpad >/dev/null || true
	fi
	if [[ "$status" != 0 ]]; then
		compose exec -T -u www-data nextcloud cat data/nextcloud.log > "$work/nextcloud.log" 2>/dev/null || true
		echo "The snapshots, pads and Nextcloud log of this run are kept in $work." >&2
	elif [[ -z "${UPGRADE_WORK:-}" ]]; then
		rm -rf "$work"
	fi
	for i in "${!env_files[@]}"; do
		if [[ -f "$saved_env/$i" ]]; then
			cp -p "$saved_env/$i" "${env_files[$i]}"
		fi
	done
	rm -rf "$saved_env"
}
trap cleanup EXIT
# A run cut short is a failed one: its files stay.
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

to_app="$repo"
if [[ -n "${TO_ARCHIVE:-}" ]]; then
	mkdir "$work/to"
	tar -xzf "$TO_ARCHIVE" -C "$work/to"
	to_app="$work/to/etherpad_nextcloud"
fi
to_version="$(sed -n 's|.*<version>\([^<]*\)</version>.*|\1|p' "$to_app/appinfo/info.xml" | head -n 1)"

if [[ -n "${FROM_VERSION:-}" ]]; then
	from_version="$FROM_VERSION"
else
	from_tag="$(git -C "$repo" describe --tags --abbrev=0 --match 'v[0-9]*' HEAD)"
	# A HEAD the release is tagged on upgrades from the release before it.
	if git -C "$repo" tag --points-at HEAD | grep -qxF "$from_tag"; then
		from_tag="$(git -C "$repo" describe --tags --abbrev=0 --match 'v[0-9]*' "$from_tag^")"
	fi
	from_version="${from_tag#v}"
fi
# The published archive of each release this test upgrades from. Each
# release adds its own line once it is out (docs/release-process.md);
# without one the test stops.
case "$from_version" in
	1.1.0-beta.1) from_sha256=594bbd43956bfe944439387a61eb2217686e71d7da0cfea7d82532f1517cb7d4 ;;
	1.1.0-beta.2) from_sha256=e48536cbf72409232dd7ca9485fa50b099c2c98a091921992e1c88207395177a ;;
	*)
		echo "No checksum is pinned for $from_version. Add the one of its published archive to $0." >&2
		exit 1
		;;
esac

from_archive="${FROM_ARCHIVE:-$work/from.tar.gz}"
if [[ -z "${FROM_ARCHIVE:-}" ]]; then
	curl --fail --location --retry 3 --silent --show-error --output "$from_archive" \
		"https://github.com/ether/etherpad-integration-for-nextcloud/releases/download/v$from_version/etherpad_nextcloud-$from_version.tar.gz"
fi
# The archive its users installed, not a build of the tag.
if ! printf '%s  %s\n' "$from_sha256" "$from_archive" | shasum -a 256 --check --status; then
	echo "$from_archive is not the published archive of $from_version." >&2
	exit 1
fi
mkdir "$work/from"
tar -xzf "$from_archive" -C "$work/from"
from_app="$work/from/etherpad_nextcloud"

# A Nextcloud the release does not run on has no installation of it to
# upgrade. Its floor may name a patch, its ceiling a major.
if ! read -r from_min from_max < <(sed -n 's/.*<nextcloud min-version="\([0-9.]*\)" max-version="\([0-9]*\)[^"]*".*/\1 \2/p' "$from_app/appinfo/info.xml"); then
	echo "The info.xml of $from_version names no Nextcloud range." >&2
	exit 1
fi
if ! reaches "$nc_version" "$from_min" || (( ${nc_version%%.*} > from_max )); then
	echo "$from_version runs on Nextcloud $from_min to $from_max, not on $nc_version: no upgrade to test there."
	exit 0
fi

echo "==> $from_version on Nextcloud $nc_version"
APP_SOURCE="$from_app" bash "$here/up.sh"

# A release from before migration 5 knows the setting by its old name,
# which the upgrade takes over; up.sh sets the new one. The upgrade drops
# test_fault, which a debug instance could set; this one names no fault,
# so the release runs as without.
from_migration5=0
if [[ -f "$from_app/lib/Migration/Version000005Date20260928120000.php" ]]; then
	from_migration5=1
else
	occ config:app:delete etherpad_nextcloud delete_pad_with_file
	occ config:app:set etherpad_nextcloud delete_on_trash --value=yes
	occ config:app:set etherpad_nextcloud test_fault --value=none
fi

export E2E_ENV_FILE="$here/../.env.e2e.docker"
export NODE_EXTRA_CA_CERTS="$here/certs/ca.crt"
export UPGRADE_TARGET="$to_app"
fixtures() { node "$here/upgrade-fixtures.mjs" "$1" "$work"; }

echo "==> pads on $from_version"
fixtures seed
etherpad_stopped=1
compose stop etherpad
fixtures offline
# Not recreated: Etherpad keeps its pads in the container.
compose up -d --no-recreate --wait etherpad
etherpad_stopped=0

# Opted out after the pads were made, as an admin can: the new setting
# has to take the admin's value, and "no" is not its default.
if [[ "$from_migration5" == 0 ]]; then
	occ config:app:set etherpad_nextcloud delete_on_trash --value=no
fi

# What the upgrade may change, read from the database and Nextcloud's own
# config, not through the app the upgrade replaces. Job ids are strings:
# Nextcloud's outgrow the integers JavaScript holds exactly. The API key
# only as a hash, so the secret stays out of the files this writes.
snapshot() {
	compose exec -T -u www-data nextcloud php <<'PHP'
<?php
require '/var/www/html/lib/base.php';
$app = 'etherpad_nextcloud';
$db = \OCP\Server::get(\OCP\IDBConnection::class);

$qb = $db->getQueryBuilder();
$qb->select('b.*')
	->selectAlias($qb->createFunction('CASE WHEN fc.fileid IS NULL THEN 0 ELSE 1 END'), 'file_cached')
	->from('ep_pad_bindings', 'b')
	->leftJoin('b', 'filecache', 'fc', $qb->expr()->eq('b.file_id', 'fc.fileid'))
	->orderBy('b.file_id');
$bindings = array_map(static function (array $row): array {
	$row['file_cached'] = (bool)(int)$row['file_cached'];
	return $row;
}, $qb->executeQuery()->fetchAll());

$qb = $db->getQueryBuilder();
$qb->select('version')->from('migrations')
	->where($qb->expr()->eq('app', $qb->createNamedParameter($app)))
	->orderBy('version');
$migrations = array_column($qb->executeQuery()->fetchAll(), 'version');

$qb = $db->getQueryBuilder();
$qb->select('id', 'class', 'argument')->from('jobs')
	->where($qb->expr()->like('class', $qb->createNamedParameter('OCA%EtherpadNextcloud%')))
	->orderBy('id');
$jobs = array_map(static function (array $job): array {
	$job['id'] = (string)$job['id'];
	return $job;
}, $qb->executeQuery()->fetchAll());

$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$settings = [];
foreach ($appConfig->getKeys($app) as $key) {
	$settings[$key] = $key === 'etherpad_api_key'
		? 'sha256:' . hash('sha256', $appConfig->getValueString($app, $key))
		: $appConfig->getValueMixed($app, $key, '', null);
}
ksort($settings);

$prefix = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
$indexes = array_keys(\OCP\Server::get(\OC\DB\Connection::class)->createSchemaManager()->listTableIndexes($prefix . 'ep_pad_bindings'));
sort($indexes);

echo json_encode(compact('bindings', 'migrations', 'jobs', 'settings', 'indexes'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
PHP
}
log_lines() { compose exec -T -u www-data nextcloud sh -c 'cat data/nextcloud.log 2>/dev/null | wc -l'; }

snapshot > "$work/before.json"
log_from="$(log_lines)"

echo "==> upgrading to $to_version"
# sync-app.sh upgrades the app when a migration of it has not run; for a
# version without one, the update is run here, as Nextcloud runs it. The
# working tree goes in as sync-app.sh copies it, an archive as it is.
APP_SOURCE="${TO_ARCHIVE:+$to_app}" bash "$here/sync-app.sh"
if [[ "$(occ config:app:get etherpad_nextcloud installed_version)" != "$to_version" ]]; then
	update_app
fi
installed="$(occ config:app:get etherpad_nextcloud installed_version)"
if [[ "$installed" != "$to_version" ]]; then
	echo "The upgrade left version $installed installed, not $to_version." >&2
	exit 1
fi

snapshot > "$work/after.json"
occ config:list etherpad_nextcloud --output=json > "$work/config.json"
fixtures migrated

# On again, for the pads deleted for good to go. The web server keeps the
# app config for under 4 s (Nextcloud's LOCAL_CACHE_TTL of 3 s, which APCu
# counts in whole seconds), where occ cannot clear it.
occ config:app:set etherpad_nextcloud delete_pad_with_file --value=yes
sleep 4
echo "==> pads on $to_version"
fixtures verify

# The jobs the upgrade kept and the ones it added, run on the code they now
# meet: a kept job's argument is the old release's.
node -e 'for (const job of JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")).jobs) console.log(job.id)' "$work/after.json" > "$work/job-ids"
while IFS= read -r job_id; do
	# Not the loop's stdin: docker would read the remaining ids.
	occ background-job:execute --force-execute "$job_id" </dev/null
done < "$work/job-ids"

# The next update runs the repair steps and adds the jobs again; they have
# to find nothing to do.
snapshot > "$work/settled.json"
update_app
snapshot > "$work/repeated.json"
if ! cmp -s "$work/settled.json" "$work/repeated.json"; then
	diff -u "$work/settled.json" "$work/repeated.json" || true
	echo "Updating again changed what the first update left." >&2
	exit 1
fi

# The warnings and errors about the app since the upgrade began: what went
# wrong without failing a request or a command. Nextcloud logs a job that
# throws under "core", so the line's own mention of the app decides. Read
# before the suite, which logs its own on purpose.
compose exec -T -u www-data nextcloud sh -c "tail -n +$((log_from + 1)) data/nextcloud.log 2>/dev/null" > "$work/upgrade.log"
# A line about the app that is no JSON fails the check as well, with its
# first 500 characters shown.
node -e '
const lines = require("fs").readFileSync(process.argv[1], "utf8").split("\n").filter((line) => /etherpad_nextcloud|EtherpadNextcloud/.test(line))
const found = []
for (const line of lines) {
	let entry
	try {
		entry = JSON.parse(line)
	} catch {
		found.push(`not JSON: ${line.slice(0, 500)}`)
		continue
	}
	if (entry.level >= 2) found.push(`level ${entry.level}, ${entry.app}: ${entry.message}`)
}
for (const line of found) console.error(line)
process.exit(found.length === 0 ? 0 : 1)
' "$work/upgrade.log"

echo "==> the suite on the upgraded stack"
bash "$here/run-suite.sh"

echo
echo "$from_version -> $to_version passed on Nextcloud $nc_version. The stack is kept for a look, as the compose"
echo "project $COMPOSE_PROJECT_NAME: docker compose and the scripts here reach it with COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME."
echo "Remove it with:"
echo "  docker compose -p $COMPOSE_PROJECT_NAME -f tests/e2e/docker/compose.yml down -v --remove-orphans"
