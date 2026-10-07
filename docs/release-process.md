# Release Process

This project uses a lightweight release flow:

1. Run the local check.
2. Run the end-to-end suite.
3. Tag the release.
4. Deploy, and run the end-to-end suite against the deployed instance.

Pushing the tag is the last manual step: `.github/workflows/release.yml` builds
the tarball and publishes the release. What has to be true before the tag is
pushed is listed under [3) Tagging](#3-tagging).

The workflow refuses a tag that does not match `appinfo/info.xml`, and a tag
whose `js/` bundles are not a fresh build of `src/`, so the bump has to be
committed before the tag is placed.

## 1) Reproducible Check (Required)

Run from repo root:

```bash
./tests/integration/release-check.sh
```

It refuses a dirty working tree, then runs `vendor/bin/phpunit --testsuite unit`
(install once with `composer install --no-interaction`).

Frontend checks are separate and should be run before release/deploy whenever
`src/`, `package.json`, or Vite/Vitest config changed:

```bash
npm test
npm run build
```

The Vite build writes runtime assets to `js/`; those built files must be present
in the deployed app.

## 2) End-to-End Checks

The end-to-end checks are a Playwright suite that drives a real Nextcloud and
Etherpad: creating and opening pads, sharing, the trash and its restore, pads
Etherpad lost, public links, legacy migration, the session cookie. CI runs it
on every pull request that touches the app, against the oldest and newest
supported Nextcloud with Etherpad 2 and against the newest with Etherpad 3,
and nightly against every supported major.

Before a release, run it once more against the container stack:

```bash
tests/e2e/docker/up.sh
tests/e2e/docker/run-suite.sh
```

Or against an instance of your own, with a dedicated test account in
`tests/e2e/.env.e2e`:

```bash
npm run test:e2e
```

Setup, the variables, and what each spec covers are in
[tests/e2e/README.md](../tests/e2e/README.md). How the app answers when
Etherpad cannot be reached (`503` with `retryable`) is held by the PHPUnit
suite rather than by an outage the release has to stage. The session cookie a
protected open sets - one, and what it carries - is described in
[etherpad-integration.md](etherpad-integration.md) and held by
`protected-session-cookie-httponly.spec.ts`.

## 3) Tagging

Pushing the tag publishes the release, so everything it reads has to be on the
tagged commit already:

- `appinfo/info.xml` carries the new version, and `package.json` plus both
  version fields in `package-lock.json` agree with it. The bundles take their
  version from `package.json`, so `npm run build` has to have run after that
  bump and `js/` has to be committed.
- `CHANGELOG.md` has a section for the version, with entries under it.
- `docs/release-notes/<version>.md` holds the prose readers get. Without it the
  CHANGELOG section is published instead, and afterwards is too late - the
  notes are read when the tag arrives.

Then:

```bash
git tag -a vX.Y.Z -m "Etherpad Integration for Nextcloud vX.Y.Z"
git push origin HEAD
git push origin vX.Y.Z
```

`.github/workflows/release.yml` refuses the tag if any of the above is missing,
before it builds anything. Otherwise it builds the tarball with
`scripts/build-release-tarball.sh` and publishes the release with it attached,
marking a pre-release suffix as a pre-release. Re-running the workflow after a
failed upload is safe; it does not try to create a release that already exists.

## 4) Post-Deploy Smoke

Run the end-to-end suite against the deployed instance, with a dedicated test
account in `tests/e2e/.env.e2e` (the specs create and delete files there):

```bash
npm run test:e2e
```

Then look in the server log for a query-budget warning on
`PadCreateController::create` (`executed N queries`); there should be none.

Optional deploy helper (rsync with production-safe excludes):

```bash
DEPLOY_SSH_TARGET="user@host" \
DEPLOY_APP_PATH="/var/www/virtual/user/html/apps/etherpad_nextcloud" \
./scripts/deploy-rsync.sh
```

Notes:

- The file set is `scripts/app-file-excludes.sh`, the same one the release
  tarball and the Docker e2e stack use, so a test server carries exactly what
  users install. Excluded are `src/`, `tests/`, `scripts/`, `dist/`,
  `.github/`, the build and tooling configs, and `node_modules/`/`vendor/`;
  the built `js/` directory ships.
- `DRY_RUN=1` prints the changes without making them.
- Set `RSYNC_DELETE=1` only when you explicitly want remote cleanup. It does
  not remove files matching an exclude — rsync leaves those alone. A target
  that was deployed with an older, wider file set keeps them until they are
  removed by hand:

  ```bash
  source scripts/app-file-excludes.sh
  rsync -az --itemize-changes --dry-run --delete --delete-excluded \
    "${RSYNC_EXCLUDES[@]}" ./ user@host:/path/to/apps/etherpad_nextcloud/
  ```

  Read the `*deleting` lines first; drop `--dry-run` once they look right.
