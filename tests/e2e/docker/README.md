# Throwaway e2e target

SPDX-License-Identifier: AGPL-3.0-or-later

A self-contained Nextcloud + Etherpad stack for the Playwright suite, so
a run does not depend on a long-lived instance and its unrelated apps.
This is what the `e2e` workflow uses, across the Nextcloud majors
`appinfo/info.xml` declares support for.

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
tests/e2e/docker/up.sh          # NC_VERSION=31|32|33, default 33
tests/e2e/docker/run-suite.sh   # the suite against that stack
docker compose -f tests/e2e/docker/compose.yml down -v --remove-orphans
```

`up.sh` refuses to run while a stack already exists — it promises a
fresh installation, and pointing it at a volume from another
`NC_VERSION` fails deep inside the container where you cannot see it.
Remove the old one with `down -v` first, or use `sync-app.sh` if you only
want your code changes in the stack you already have.

`up.sh` boots the stack, installs and configures the app, creates the
second test account with app passwords for both, and writes
`tests/e2e/.env.e2e.docker`. Your own `tests/e2e/.env.e2e` is never
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
