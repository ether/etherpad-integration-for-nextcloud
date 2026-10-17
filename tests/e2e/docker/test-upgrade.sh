#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Copyright (c) 2026 Jacob Bühler
#
# The last release, as published, on a stack of its own, with pads in the
# states its users can leave them in, upgraded to this app. The upgrade
# has to change what upgrade-fixtures.mjs says its migrations change, and
# nothing else; the suite then runs on the upgraded stack.
#
#   tests/e2e/docker/test-upgrade.sh
#
# FROM_VERSION is the release to upgrade from, by default the last tag
# before HEAD. Its archive is fetched from the release, or taken from
# FROM_ARCHIVE, and checked against the checksum pinned below either way.
# TO_ARCHIVE is a release archive to upgrade to instead of the working
# tree. NC_VERSION and EP_VERSION are up.sh's.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"

# A project of its own, so no container or volume of the stack someone
# works in is this test's. Not taken from the shell, where a name of that
# stack would point the test at it.
export COMPOSE_PROJECT_NAME=epnc-upgrade
compose() { docker compose -f "$here/compose.yml" "$@"; }
occ() { compose exec -T -u www-data nextcloud php occ "$@"; }
update_app() { compose exec -T -u www-data nextcloud php -r 'require "lib/base.php"; exit(\OC_App::updateApp("etherpad_nextcloud") ? 0 : 1);'; }

from_version="${FROM_VERSION:-$(git -C "$repo" describe --tags --abbrev=0 --match 'v[0-9]*' HEAD)}"
from_version="${from_version#v}"
# The published archive of each release this test upgrades from. Each
# release adds its own line once it is out; without one the test stops.
case "$from_version" in
	1.1.0-beta.1) from_sha256=594bbd43956bfe944439387a61eb2217686e71d7da0cfea7d82532f1517cb7d4 ;;
	*)
		echo "No checksum is pinned for $from_version. Add the one of its published archive to $0." >&2
		exit 1
		;;
esac

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

work="$(mktemp -d)"
etherpad_stopped=0
cleanup() {
	local status=$?
	# Left stopped, Etherpad fails every open on the stack kept for a look.
	if [[ "$etherpad_stopped" == 1 ]]; then
		compose start etherpad >/dev/null || true
	fi
	if [[ "$status" == 0 ]]; then
		rm -rf "$work"
	else
		echo "The snapshots and pads of this run are kept in $work." >&2
	fi
}
trap cleanup EXIT

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

to_app="$repo"
if [[ -n "${TO_ARCHIVE:-}" ]]; then
	mkdir "$work/to"
	tar -xzf "$TO_ARCHIVE" -C "$work/to"
	to_app="$work/to/etherpad_nextcloud"
fi
to_version="$(sed -n 's|.*<version>\([^<]*\)</version>.*|\1|p' "$to_app/appinfo/info.xml" | head -n 1)"

# A Nextcloud the release does not run on has no installation of it to
# upgrade. up.sh's default is compose.yml's.
nc_version="${NC_VERSION:-$(sed -n 's/.*${NC_VERSION:-\([^}]*\)}.*/\1/p' "$here/compose.yml" | head -n 1)}"
if ! read -r from_min from_max < <(sed -n 's/.*<nextcloud min-version="\([0-9]*\)[^"]*" max-version="\([0-9]*\)[^"]*".*/\1 \2/p' "$from_app/appinfo/info.xml"); then
	echo "The info.xml of $from_version names no Nextcloud range." >&2
	exit 1
fi
if (( ${nc_version%%.*} < from_min || ${nc_version%%.*} > from_max )); then
	echo "$from_version runs on Nextcloud $from_min to $from_max, not on $nc_version: no upgrade to test there."
	exit 0
fi

echo "==> $from_version on Nextcloud $nc_version"
APP_SOURCE="$from_app" bash "$here/up.sh"

# A release from before migration 5 knows the setting by its old name,
# which the upgrade takes over; up.sh sets the new one. The upgrade drops
# test_fault, which a debug instance could set; this one names no fault,
# so the release runs as without.
if [[ ! -f "$from_app/lib/Migration/Version000005Date20260928120000.php" ]]; then
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
compose start --wait etherpad
etherpad_stopped=0

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
# version without one, the update is run here, as Nextcloud runs it.
APP_SOURCE="$to_app" bash "$here/sync-app.sh"
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

# The app's warnings and errors since the upgrade began: what went wrong
# without failing a request. Read before the suite, which logs its own on
# purpose.
compose exec -T -u www-data nextcloud sh -c "tail -n +$((log_from + 1)) data/nextcloud.log 2>/dev/null" > "$work/upgrade.log"
node -e '
const lines = require("fs").readFileSync(process.argv[1], "utf8").split("\n").filter(Boolean)
const logged = lines.map((line) => JSON.parse(line)).filter((entry) => entry.app === "etherpad_nextcloud" && entry.level >= 2)
for (const entry of logged) console.error(`level ${entry.level}: ${entry.message}`)
process.exit(logged.length === 0 ? 0 : 1)
' "$work/upgrade.log"

echo "==> the suite on the upgraded stack"
bash "$here/run-suite.sh" --retries=0

echo
echo "$from_version -> $to_version passed on Nextcloud $nc_version. The stack is kept for a look; remove it with:"
echo "  docker compose -p $COMPOSE_PROJECT_NAME -f tests/e2e/docker/compose.yml down -v --remove-orphans"
