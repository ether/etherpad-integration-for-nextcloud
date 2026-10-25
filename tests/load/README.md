# Load scripts

SPDX-License-Identifier: AGPL-3.0-or-later

Scripts that measure what open viewers cost the server and Etherpad, on a
container stack made for them alone. `docs/architecture.md` ("Open
viewers") has what they measured. They need Node 24 and the stack's
hostnames in `/etc/hosts`, as `tests/e2e/docker/README.md` says.

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

Then lay `compose.load.yml` over it, before any pad is made: Etherpad
then logs each API call, which is how the scripts count them, and Redis
and Postgres are set up as an instance under load wants them. That file
says why. Point Nextcloud at Redis, and shorten the sync interval, so a
run of a minute covers a dozen intervals:

```bash
load() { docker compose -f tests/e2e/docker/compose.yml -f tests/load/compose.load.yml "$@"; }
load up -d --wait etherpad db redis
occ() { load exec -T -u www-data nextcloud php occ "$@"; }
occ config:system:set redis host --value=redis
occ config:system:set redis port --value=6379 --type=integer
occ config:system:set memcache.locking --value='\OC\Memcache\Redis'
occ config:system:set memcache.distributed --value='\OC\Memcache\Redis'
occ config:app:set etherpad_nextcloud sync_interval_seconds --value=5
```

Remove it with:

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

Give each run pads of its own with `--skip`: a run that overloads the
stack can leave locks on its files for up to an hour, and the next run on
those files would measure them. A run's JSON goes to `--out` as well.
