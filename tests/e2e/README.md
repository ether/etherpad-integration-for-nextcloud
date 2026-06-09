# End-to-end tests (Playwright)

SPDX-License-Identifier: AGPL-3.0-or-later

Browser-driven tests that complement our PHPUnit and vitest unit suites:
they drive a real Nextcloud + Etherpad and walk through the flows users
and admins actually perform in the browser — creating and opening pads
from the Files UI, templates, trash and restore, sharing, public-share
access, and the admin health check.

## What it talks to

The specs are **target-agnostic** — they drive whatever Nextcloud
instance `E2E_BASE_URL` points at. For local development that's easiest
against an existing test instance (your own NC, or the shared test
server). A reproducible Docker NC+Etherpad target for CI is a later
phase ([#112](https://github.com/Jaggob/etherpad-integration-for-nextcloud/issues/112));
because the specs only depend on `E2E_BASE_URL` and the
documented env vars, adding it won't require rewriting tests.

> Use a **dedicated throwaway test account**. The specs create and delete
> `.pad` files on the target instance.

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
npm run test:e2e        # headless
npm run test:e2e:ui     # Playwright UI mode (watch + time-travel)
```

The `setup` project logs in once per account and saves the sessions to
`tests/e2e/.auth/` (gitignored); every spec reuses the stored
`storageState` instead of re-logging in. `E2E_LOGIN_URL` defaults to
`/login` — override it for instances with a custom login front door,
for example `/login?noredir=1#body-login`.

## Layout

```
tests/e2e/
  playwright.config.ts     baseURL from env, serial, retry + trace on failure
  auth.setup.ts            logs in each account -> .auth/state*.json
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

Specs name their files `e2e-<label>-<timestamp>.pad` and delete them in
`afterAll` via WebDAV. `E2E_APP_PASSWORD` is required for these
non-browser requests, matching the existing `NC_APP_PASSWORD` pattern in
`tests/integration/*.sh`.

Keep `E2E_PASS` and `E2E_APP_PASSWORD` separate:

- `E2E_PASS` logs into the interactive Nextcloud web UI once and stores
  Playwright's browser `storageState`.
- `E2E_APP_PASSWORD` is used only for BasicAuth requests outside the
  browser, such as WebDAV cleanup and plugin-API calls.

> Note: a brand-new account shows Nextcloud's first-run wizard modal,
> which blocks clicks. `auth.ts` dismisses it after login; on a shared
> instance you can also disable it once with
> `occ app:disable firstrunwizard`.
