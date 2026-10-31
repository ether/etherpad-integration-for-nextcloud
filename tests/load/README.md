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

Give each of the runs below pads of its own: a run that overloads the
stack can leave locks on its files for up to an hour, and deleted pads are
gone. `viewers.mjs` and `sweep.mjs` take them from `pads.json` with
`--skip`; for `trash-folder.mjs`, seed a folder anew. The scripts below
also write their JSON to `--out`.

## Viewers

```bash
node tests/load/viewers.mjs --skip 0 --pads 50 --viewers-per-pad 5 --duration 60
node tests/load/viewers.mjs --skip 50 --pads 50 --viewers-per-pad 5 --duration 60 --unpaced
```

opens each pad once per viewer and sends a timed sync every interval the
server names, while a fifth of the pads (`--typed`) get a character a
second through Etherpad's API. `--unpaced` leaves out `periodic=1`, so
the server does not pace the syncs. Each viewer keeps its cookies, as a
tab does: without them every request starts a PHP session of its own,
which made a deferred sync take 27 ms instead of 17. After a warm-up of
one interval it reports, for the syncs sent in `--duration` seconds:

- how many went out a second, and what they answered, with the median,
  95th percentile and longest time for each answer;
- `ticksSkippedInFlight`: ticks a viewer let pass because its last sync
  had not come back, as the browser's loop does - a sign of an overloaded
  stack;
- looks (`unchanged` or `updated`) per pad and interval, and writes per
  typed pad and interval;
- the Etherpad calls the app made in that time, all, per sync - deferred
  ones counted too - and per look, an `unchanged` or `updated` answer; a
  `locked` one may have asked Etherpad as well, so near the stack's limit
  the calls per look come out high;
- the file versions written;
- what each container took of the cores halfway through (`cpuHalfway`,
  one sample of `docker stats`).

## Deletes

```bash
node tests/load/sweep.mjs --skip 2000 --pads 1000
node tests/load/seed-pads.mjs --pads 100 --per-folder 100 --folder trash-test --state tests/load/.work/trash.json
node tests/load/trash-folder.mjs --state tests/load/.work/trash.json --opens-per-pad 2
```

`sweep.mjs` deletes pads' files past the trash and then runs the sweep
through the admin's pending pad check until no row waits, a run takes
none, or 50 runs are done; each run says how many rows are left.
`trash-folder.mjs` puts a folder of opened pads in the trash and restores
it. Both report the time of each request and the Etherpad calls in it.
A folder put in the trash queues a `RevokeGroupSessionsJob` for each group
its walk reached, 100 at most, and past those a `RevokeFolderSessionsJob`
that finds the rest. Only cron runs them, which the stack has none of: they
stay in `oc_jobs`, and `trash-folder.mjs` reports how many of its folder's
groups have a job, and how many folder jobs wait. Past 100 pads, fewer
groups have one than the folder holds, and that loses none.

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
consistency check, the pending pad check - which it refuses while rows
wait for the sweep - and an account's deletion, whose pads it sweeps
afterwards. Run `sweep.mjs` and `trash-folder.mjs` on real pads beside
them.

`--plans`, for `sweep.mjs` and `trash-folder.mjs`, and `measure` on its
own, have Postgres explain the statements on the app's table while the
requests run (`auto_explain`), and add the plans to the report; explained
statements are slower, so the times come from a run without. The setting
outlives the script: one that ends early without the reset - killed hard,
say - leaves Postgres explaining every statement of every request, and
the next run refuses to start until it is reset by hand:

```bash
load exec -T db psql -U nextcloud -d nextcloud -c "load 'auto_explain'" \
	-c 'alter system reset session_preload_libraries' -c 'alter system reset auto_explain.log_min_duration' \
	-c 'alter system reset auto_explain.log_analyze' -c 'alter system reset auto_explain.log_buffers' \
	-c 'select pg_reload_conf()'
```
