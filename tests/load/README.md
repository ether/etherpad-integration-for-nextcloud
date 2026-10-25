# Load scripts

SPDX-License-Identifier: AGPL-3.0-or-later

Scripts that measure what open viewers, deletes and the admin's checks
cost the server and Etherpad, on a container stack made for them alone.
`docs/architecture.md` ("Cost at scale") has what they measured. They need
Node 24 and the stack's hostnames in `/etc/hosts`, as
`tests/e2e/docker/README.md` says. The load runs on the machine the stack
runs on, so the two share its cores.

## The stack

A stack of its own, `epnc-load`, so the pads the scripts make stay out of
the e2e suite's. It needs port 443 like every stack there, so stop
another one first. `up.sh` writes `tests/e2e/.env.e2e.docker` and
`tests/e2e/docker/.env` for it; copy yours aside first and put them back
when you are done.

```bash
export COMPOSE_PROJECT_NAME=epnc-load
tests/e2e/docker/up.sh
```

Then lay `compose.load.yml` over it; that file says what it changes and
why. Point Nextcloud at Redis, let the app reach Etherpad's API through
the Caddy site that counts the calls, and shorten the sync interval, so a
run of a minute covers a dozen intervals:

```bash
load() { docker compose -f tests/e2e/docker/compose.yml -f tests/load/compose.load.yml "$@"; }
load up -d --wait caddy db redis
occ() { load exec -T -u www-data nextcloud php occ "$@"; }
occ config:system:set redis host --value=redis
occ config:system:set redis port --value=6379 --type=integer
occ config:system:set memcache.locking --value='\OC\Memcache\Redis'
occ config:system:set memcache.distributed --value='\OC\Memcache\Redis'
occ config:app:set etherpad_nextcloud etherpad_api_host --value='http://caddy:9002'
occ config:app:set etherpad_nextcloud sync_interval_seconds --value=5
```

Leave Etherpad's log level as it is: at debug it logs every database
operation, gigabytes in a few runs, and slowed the whole stack threefold
when it did. Etherpad keeps its pads in its container, so recreating that
container loses them.

Remove the stack with:

```bash
docker compose -p epnc-load -f tests/e2e/docker/compose.yml -f tests/load/compose.load.yml down -v --remove-orphans
```

## Pads

```bash
export NODE_EXTRA_CA_CERTS="$PWD/tests/e2e/docker/certs/ca.crt"
node tests/load/seed-pads.mjs --pads 10000
```

makes protected pads through the app, 500 to a folder of the e2e user, and
writes their ids to `tests/load/.work/pads.json`. It reports how long a
create took.

Give each of the runs below pads of its own with `--skip`: a run that
overloads the stack can leave locks on its files for up to an hour, and
deleted pads are gone. Each script writes its JSON to `--out` as well.

## Viewers

```bash
node tests/load/viewers.mjs --skip 0 --pads 50 --viewers-per-pad 5 --duration 60
node tests/load/viewers.mjs --skip 50 --pads 50 --viewers-per-pad 5 --duration 60 --unpaced
```

opens each pad once per viewer and sends a timed sync every interval the
server names, while a fifth of the pads (`--typed`) get a character a
second through Etherpad's API. `--unpaced` leaves out `periodic=1`, as
the viewers did before the server paced them. After a warm-up of one
interval it reports, for the syncs sent in `--duration` seconds:

- how many went out a second, and what they answered, with the median,
  95th percentile and longest time for each answer;
- `ticksSkippedInFlight`: ticks a viewer let pass because its last sync
  had not come back, as the browser's loop does - a sign of an overloaded
  stack;
- looks (`unchanged` or `updated`) per pad and interval, and writes per
  typed pad and interval;
- the Etherpad calls the app made in that time, all and per sync;
- the file versions written.

## Deletes

```bash
node tests/load/sweep.mjs --skip 2000 --pads 1000
node tests/load/seed-pads.mjs --pads 100 --per-folder 100 --folder trash-test --state tests/load/.work/trash.json
node tests/load/trash-folder.mjs --state tests/load/.work/trash.json --opens-per-pad 2
```

`sweep.mjs` deletes pads' files past the trash and then runs the sweep
through the admin's pending pad check until no row waits.
`trash-folder.mjs` puts a folder of opened pads in the trash and restores
it. Both report the time of each request and the Etherpad calls in it.

## Large tables

```bash
node tests/load/large-tables.mjs fill --storages 1000 --files 2000
node tests/load/large-tables.mjs measure
node tests/load/large-tables.mjs clear
```

`fill` writes made-up accounts' files and pads straight into the database,
two million file cache rows by default, with the pads spread over the
whole range of file ids and some rows' files gone. No Etherpad pad stands
behind them, so `measure` takes only what reads the tables: the
consistency check, the pending pad check and an account's deletion. Run
`sweep.mjs` and `trash-folder.mjs` on real pads beside them.

`--plans`, for `sweep.mjs` and `trash-folder.mjs`, and `measure` on its
own, have Postgres explain the statements on the app's table while the
requests run (`auto_explain`), and add the plans to the report; explained
statements are slower, so the times come from a run without.
