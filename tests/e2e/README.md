# End-to-end tests (Playwright)

SPDX-License-Identifier: AGPL-3.0-or-later

Browser-driven tests that complement our PHPUnit and vitest unit suites:
they drive a real Nextcloud + Etherpad and walk through the flows users
and admins actually perform in the browser — creating and opening pads
from the Files UI, templates, trash and restore, sharing, public-share
access, and the admin health check.

## What it talks to

The specs are **target-agnostic** — they drive whatever Nextcloud
instance `E2E_BASE_URL` points at. There are two ways to give them one:

- **A throwaway container stack**, built from images and thrown away
  afterwards — see [`docker/README.md`](docker/README.md). This is what
  CI uses, across every supported Nextcloud major, and it is the easiest
  way to run the suite locally too.
- **An existing instance** (your own Nextcloud, or the shared test
  server), configured through `.env.e2e` as described below.

`E2E_ENV_FILE` chooses between them: point it at a file and that file
wins over anything already exported in your shell. Without it the suite
falls back to `tests/e2e/.env.e2e`.

> Use a **dedicated throwaway test account**. The specs create and delete
> `.pad` files on the target instance, and the trash sweep at the end of
> a run deletes its own fixtures permanently.

## Setup

```bash
# 1. install the browser binaries once (Playwright itself is a devDep)
npx playwright install chromium

# 2. configure your target
cp tests/e2e/.env.e2e.example tests/e2e/.env.e2e
$EDITOR tests/e2e/.env.e2e
```

Required: `E2E_BASE_URL`, `E2E_USER`, `E2E_PASS`, `E2E_APP_PASSWORD`
(plus optional `E2E_LOGIN_URL`). The cross-user specs additionally need a
second account — `E2E_USER2`, `E2E_USER2_PASS`, `E2E_USER2_APP_PASSWORD`;
they skip cleanly when it isn't configured. See `.env.e2e.example` for
what each one is for.

## Run

```bash
tests/e2e/docker/run-suite.sh   # against the container stack
npm run test:e2e                # against the instance in .env.e2e
npm run test:e2e:ui             # Playwright UI mode (watch + time-travel)
```

`run-suite.sh` is the entry point for the container target: besides the
env file it sets `NODE_EXTRA_CA_CERTS`, which node reads at startup and
which therefore cannot come from an env file.

The `setup` project logs in once per account and saves the sessions to
`tests/e2e/.auth/` (gitignored); every spec reuses the stored
`storageState` instead of re-logging in. `E2E_LOGIN_URL` defaults to
`/login` — override it for instances with a custom login front door,
for example `/login?noredir=1#body-login`.

## Layout

```
tests/e2e/
  playwright.config.ts     target selection, serial, retry + trace on failure
  auth.setup.ts            logs in each account -> .auth/state*.json
  global-setup.ts          stamps this run's id
  global-teardown.ts       sweeps this run's fixtures out of the trash
  docker/                  throwaway Nextcloud + Etherpad stack (see its README)
  fixtures/
    env.ts                 required-env reader (+ optional secondary account)
    auth.ts                login flow, stored-state paths, wizard dismissal
    dav.ts                 WebDAV + OCS + plugin-API helpers (app password)
    nextcloud.ts           Files-app browser helpers
  specs/                   one file per flow (see Coverage)
```

Selectors prefer stable hooks (NC `data-cy-*`, our own `data-testid`)
over localized text so specs survive UI-language changes. Content checks
usually go through the plugin's own HTTP endpoints + WebDAV rather than
the Etherpad API or editor typing; the author-display-name spec is the
one deliberate exception because it verifies the real Etherpad session UI.

## Coverage

Each `specs/*.spec.ts` covers one flow:

- **pad-create-public** — internal public pad create + open, reopening an
  existing pad, and external pad from URL → external-snapshot viewer.
- **pad-create-template** — create from the blank template-picker entry.
- **pad-author-display-name** — protected pad opens with the NC account's
  display name visible in Etherpad's user list.
- **pad-template-placeholders** — `{{date}}` / `{{user}}` substitution
  when creating from a Templates-folder `.pad`.
- **pad-move-rename** — the binding (keyed on file id) survives an
  in-place rename and a move into a subfolder.
- **pad-orphan-recovery** — a binding-less `.pad` (WebDAV copy) shows the
  recovery card and "Open the original" navigates to the source pad.
- **pad-snapshot-roundtrip** — recover-from-snapshot pushes a known
  marker into a new pad and sync reads it back (the content copy that
  restore and recover share).
- **pad-trash-restore** — trash + restore round-trip, pad reopens.
- **pad-user-share** — user-to-user share grants access, revoke removes
  it (NC boundary; Etherpad's own session-cookie window is out of scope).
- **pad-ownership-boundary** — cross-user `open-by-id` is rejected.
- **public-share-view** — public share opens without login, plus auth
  boundaries (tokenless access, invalid / non-pad tokens).
- **pad-legacy-migration** — an `[InternetShortcut]` Ownpad file migrates
  to YAML frontmatter on first open.
- **admin-health-check** — the admin "Test Etherpad connection" button.

## Cleanup

Every file and folder a spec creates must be named through
`uniqueName()` (or `uniquePadName()`) from `fixtures/nextcloud.ts`. The
name it builds — `e2e-<label>-r<runid>-<timestamp>[.pad|.txt]` — is what
the trash sweep recognises afterwards. A hand-built name leaks forever,
silently, so `fixtures/fixture-name.ts` is the single place that both
builds and matches names, and it throws on a label or extension it could
not recognise later.

Specs delete their files in `afterAll` via WebDAV. `E2E_APP_PASSWORD` is
required for these non-browser requests, matching the existing
`NC_APP_PASSWORD` pattern in `tests/integration/*.sh`.

That `DELETE` only moves a file to the trash, so `global-teardown.ts`
sweeps the trash at the end of the run. Without it a shared account
collects a dozen entries per green run, and a single unreadable one
breaks the trash listing for every spec that reads it.

The sweep purges **only entries carrying this run's id**, which
`global-setup.ts` stamps before the workers start. Fixtures from another
run are left alone — that suite may be about to restore the very entry —
and entries from before run ids existed are reported for a person to
deal with, never deleted. Ownership is never inferred from age: a run
that starts later has later timestamps throughout, so time cannot say
who created what. Every purge is named in the output, because it is the
one irreversible thing the suite does.

Keep `E2E_PASS` and `E2E_APP_PASSWORD` separate:

- `E2E_PASS` logs into the interactive Nextcloud web UI once and stores
  Playwright's browser `storageState`.
- `E2E_APP_PASSWORD` is used only for BasicAuth requests outside the
  browser, such as WebDAV cleanup and plugin-API calls.

> Note: a brand-new account shows Nextcloud's first-run wizard modal,
> which blocks clicks. `auth.ts` dismisses it after login; on a shared
> instance you can also disable it once with
> `occ app:disable firstrunwizard`.
