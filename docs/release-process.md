# Release Process

This project uses a lightweight release flow:

1. Run a reproducible local check script.
2. Run optional failure-path checks.
3. Tag the release.
4. Deploy and run post-deploy smoke checks.

## 1) Reproducible Check (Required)

Run from repo root:

```bash
./tests/integration/release-check.sh
```

What it does:

- Verifies required local tools (`git`, `php`).
- Fails on dirty working tree by default.
- Runs local tiny unit test:
  - `php tests/unit/padfile-pathnormalizer-test.php`
- Runs PHPUnit unit suite when available:
  - `vendor/bin/phpunit --testsuite unit`
  - If `vendor/bin/phpunit` is missing, install once with:
    - `composer install --no-interaction`
- If Nextcloud test credentials are present, runs core E2E checks:
  - pad flow
  - protected cookie contract (session cookie attrs + no `HttpOnly` for current Etherpad runtime compatibility)
  - lifecycle state guards
  - public folder share flow
  - public single-file share flow
  - external URL security checks

Frontend checks are separate and should be run before release/deploy whenever
`src/`, `package.json`, or Vite/Vitest config changed:

```bash
npm test
npm run build
```

The Vite build writes runtime assets to `js/`; those built files must be present
in the deployed app.

Environment variables for E2E:

- `NC_BASE_URL`
- `NC_USER`
- `NC_APP_PASSWORD`

Optional first argument:

- path prefix used by E2E scripts, for example:

```bash
./tests/integration/release-check.sh "/release-candidate"
```

## 2) Optional Failure-Path Checks

These checks intentionally expect errors and usually require an Etherpad outage/misconfiguration phase:

```bash
RUN_FAILURE_PATHS=1 FAILURE_PATHS_PREPARED=1 NC_BASE_URL=... NC_USER=... NC_APP_PASSWORD=... ./tests/integration/release-check.sh "/release-failure"
```

Included:

- sync failure path
- trash deferred-delete failure path
- restore failure path

Optional debug-only lifecycle fault-injection checks:

```bash
RUN_DEBUG_FAULT_PATHS=1 NC_BASE_URL=... NC_USER=... NC_APP_PASSWORD=... ./tests/integration/release-check.sh "/release-debug-faults"
```

Notes:

- `release-check.sh` will only run failure-path tests when both flags are set:
  - `RUN_FAILURE_PATHS=1`
  - `FAILURE_PATHS_PREPARED=1`
- This prevents false failures on healthy environments where outage conditions were not prepared.
- Requires admin credentials.
- Requires Nextcloud `debug` mode enabled.

## 3) Tagging

After checks pass:

```bash
git tag -a vX.Y.Z -m "Etherpad Integration for Nextcloud vX.Y.Z"
git push origin HEAD
git push origin vX.Y.Z
```

## 4) Post-Deploy Smoke

Re-run required checks against target environment:

```bash
NC_BASE_URL=... NC_USER=... NC_APP_PASSWORD=... ./tests/integration/release-check.sh "/release-post-deploy"
```

Optional deploy helper (rsync with production-safe excludes):

```bash
DEPLOY_SSH_TARGET="user@host" \
DEPLOY_APP_PATH="/var/www/virtual/user/html/apps/etherpad_nextcloud" \
./scripts/deploy-rsync.sh
```

Notes:

- Excludes by default: `.git/`, `node_modules/`, `vendor/`, `tests/`, `docs/`, `.phpunit.cache/`, `_copy_probe/`, `.DS_Store`.
- `node_modules/` should not be deployed, but the built `js/` directory should be.
- Set `RSYNC_DELETE=1` only when you explicitly want remote cleanup.

## 5) Server Log Verification (Recommended)

After deploy, verify that the historical query-budget warning is not present anymore:

```bash
ssh <server> 'grep -n "PadController::create executed" /path/to/nextcloud.log | tail -n 20'
ssh <server> 'grep -nE "executed [0-9]+ queries" /path/to/nextcloud.log | tail -n 20'
```

Expected result: no new warnings for `PadController::create` above the Nextcloud warning threshold.

## 6) Cookie Header Contract (Protected Pads)

- Protected pad open responses intentionally attach one explicit `Set-Cookie` header for Etherpad session bootstrapping.
- We use explicit cookie attributes (`Domain`, `Secure`, `SameSite=None`) for cross-subdomain iframe sessions.
- Current contract: this app writes one Etherpad session cookie on these responses; no additional custom cookies are added by this app on the same response.
- If future features require multiple custom cookies on the same response, cookie handling must be extended deliberately and covered by dedicated tests.
