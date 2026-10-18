# Throwaway e2e target

SPDX-License-Identifier: AGPL-3.0-or-later

A self-contained Nextcloud + Etherpad stack for the Playwright suite, so
a run does not depend on a long-lived instance and its unrelated apps.
This is what the `e2e` workflow uses. A pull request there runs the two
ends of the range `appinfo/info.xml` declares — 32.0.9 and 34 — and the
nightly run covers the majors in between. Those middle majors are worth
a local run when you touch something version-sensitive, since nothing
gates a merge on them:

```bash
NC_VERSION=33 tests/e2e/docker/up.sh
```

## One-time host setup

Both services need real hostnames under a shared parent domain — the
app refuses a non-https Etherpad base URL, and protected pads need the
session cookie to be valid for both hosts, which rules out IPs, ports on
`localhost`, and single-label names.

```bash
printf '127.0.0.1 nc.pad.test ep.pad.test\n' | sudo tee -a /etc/hosts
```

## Run

```bash
tests/e2e/docker/up.sh          # NC_VERSION=32.0.9|33|34, default 34
                                # EP_VERSION=2|3, default 2
tests/e2e/docker/run-suite.sh   # the suite against that stack
docker compose -f tests/e2e/docker/compose.yml down -v --remove-orphans
```

Both versions are chosen at `up.sh` time and written to
`tests/e2e/docker/.env`, which Compose reads by itself — so a later
`docker compose` call resolves the same versions unless the shell it runs
in sets them. Without that file, as in another worktree, Compose takes
the defaults in `compose.yml`, which are the ones `up.sh` starts with; a
stack brought up on another version could then be recreated on the
default.

`up.sh` refuses to run while a stack already exists — it promises a
fresh installation, and pointing it at a volume from another
`NC_VERSION` fails deep inside the container where you cannot see it.
Remove the old one with `down -v` first, or use `sync-app.sh` if you only
want your code changes in the stack you already have.

`up.sh` boots the stack, installs and configures the app, installs
groupfolders (the release pinned for the Nextcloud major, checked against
its checksum; a major with none pinned comes up without it, and the team
folder specs skip), creates the second test account with app passwords
for both, and writes `tests/e2e/.env.e2e.docker`. Your own `tests/e2e/.env.e2e` is never
touched; `E2E_ENV_FILE` is what selects the target.

The app is **copied** into the container by `up.sh`, not mounted: a mount
under `/var/www/html` makes that directory non-empty before the
entrypoint populates it, and Nextcloud 31 then refuses to install. So a
change in the working tree does not reach a running stack on its own —
re-run the copy:

```bash
npm run build                     # only if you touched src/
tests/e2e/docker/sync-app.sh
```

Skip that and you are testing the code as it was when the stack came up,
without any sign that you are.

## Upgrade from the last release

```bash
tests/e2e/docker/test-upgrade.sh
TO_ARCHIVE=dist/etherpad_nextcloud-<version>.tar.gz tests/e2e/docker/test-upgrade.sh
```

This installs the last release before `HEAD` (`FROM_VERSION` names
another) from its published archive, checked against the checksum pinned
in the script; `FROM_ARCHIVE` takes an archive already downloaded. Each
release adds its own checksum there once it is out, and without one the
test stops. On the release it makes pads in the states its users can leave
them in: public and protected, shared, in the trash, and trashed or deleted
for good while Etherpad was down. Then it installs the working tree over
it, or the release archive `TO_ARCHIVE` names, and runs the update as
Nextcloud runs it.

It snapshots the rows, settings, migrations, jobs and index names before
and after the update. The entries in `upgrade-fixtures.mjs` say what the
migrations that ran change in rows, settings and index names, and the
rest of these has to stay as it was, but for `installed_version`, which
has to be the new version. A migration that
runs needs an entry, empty if it changes none of these. Of the jobs, those
whose class is gone have to be gone, and those `info.xml` names have to be
there. A repair step's warning fails the update. The test then opens,
restores and deletes the pads, runs the jobs, runs the update once more,
whose snapshot has to match, and fails on any warning or error about the
app logged meanwhile. Last, it runs the whole suite on the upgraded stack.

The stack is a project of its own, `epnc-upgrade`, and is kept for a look;
`docker compose` and the scripts here reach it with
`COMPOSE_PROJECT_NAME=epnc-upgrade`.
It needs port 443 and the hostnames like every stack here, so stop another
one first. `up.sh` writes `tests/e2e/docker/.env` and
`tests/e2e/.env.e2e.docker` for it; the ones that were there before go
back when the test ends, and where there were none, the test's stay.
A failed run leaves its snapshots, pads and the Nextcloud log in a
directory it names. `UPGRADE_WORK` sets that directory, which has to be
new or empty; a passing run leaves its snapshots and pads there too.
Remove the stack with:

```bash
docker compose -p epnc-upgrade -f tests/e2e/docker/compose.yml down -v --remove-orphans
```

On GitHub the `e2e-upgrade` workflow runs it on every supported major, for
the pull requests its first job picks; that job's log says whether and
why.

## Certificates

`make-certs.sh` mints a local CA and one certificate covering both
hostnames into `certs/` (gitignored). They are reused once they exist —
`FORCE=1` regenerates them. The CA is baked
into the Nextcloud image so its health check can reach the public
Etherpad URL, and handed to node's fetch via `NODE_EXTRA_CA_CERTS`.

Chromium is told to accept it via `E2E_IGNORE_HTTPS_ERRORS=1`, set by
`run-suite.sh` for this target only. A CA this stack mints for itself says nothing
about the app's TLS behaviour, and trusting it in the browser's store
differs per platform. Runs against a real instance keep certificate
errors fatal.

## Accounts

| | |
|---|---|
| `admin` / `admin-e2e-pw` | primary; the health-check spec needs an admin |
| `e2e-tester2` / `e2e-tester2-pw` | secondary, for the cross-user specs |

Both are throwaway by construction: `down -v` drops the volumes and the
next `up.sh` starts from an empty instance.

## Optional full-text search

The NC 34 target can also install the three Nextcloud full-text-search apps
and run Elasticsearch 8 alongside the normal stack:

```bash
FULLTEXTSEARCH=1 NC_VERSION=34 tests/e2e/docker/up.sh
tests/e2e/docker/index-fulltextsearch.sh
```

The focused CI contract can be run locally as well. It creates a real pad,
syncs a unique phrase, verifies that only the plain snapshot is searchable,
checks the pad icon, and removes its fixture again:

```bash
tests/e2e/docker/test-fulltextsearch.sh
```

On GitHub, `e2e-fulltextsearch.yml` runs that check when the indexing
integration changes — and, because its paths filter names `tests/e2e/docker/**`,
whenever anything in this directory does. A change to `compose.yml`, the
`Caddyfile` or `run-suite.sh` therefore starts a Nextcloud 34 stack with
Elasticsearch even though it is not search-specific. It is also available
through `workflow_dispatch`.

This profile is deliberately not part of the regular Playwright matrix. It
downloads the checksummed 34.0.1 releases of `fulltextsearch`,
`files_fulltextsearch` and `fulltextsearch_elasticsearch` from their official
GitHub release repositories while the stack is created, and gives
Elasticsearch a 512 MiB Java heap. `ELASTICSEARCH_VERSION` can override the
pinned 8.x image when needed.

To exercise it in the browser, open <https://nc.pad.test>, create a pad and
put a distinctive phrase in it. Keep the pad open for at least five seconds
so the content is copied into the `.pad` file, run
`index-fulltextsearch.sh`, then use Nextcloud's global search. The app
contributes only the plain-text snapshot to the index: YAML frontmatter and
the stored HTML are excluded. Consequently search reflects the latest stored
snapshot, not Etherpad changes that have not yet synced.

As with the normal stack, remove everything including the Elasticsearch
index with:

```bash
docker compose -f tests/e2e/docker/compose.yml down -v --remove-orphans
```
