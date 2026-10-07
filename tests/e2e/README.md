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
npm run typecheck:e2e           # strict TypeScript over the suite, as CI runs it
```

Playwright runs the suite's TypeScript without checking its types, so a
type error would otherwise go unseen until it fails a run. The check reads
`tests/e2e/tsconfig.json`, the plain JavaScript modules too, through their
JSDoc.

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
  browser-noise-summary.mjs  groups the run's browser noise of other software
  docker/                  throwaway Nextcloud + Etherpad stack (see its README)
  fixtures/
    env.ts                 required-env reader (+ optional secondary account)
    auth.ts                login flow, stored-state paths, wizard dismissal
    dav.ts                 WebDAV + OCS + plugin-API helpers (app password)
    nextcloud.ts           Files-app browser helpers
    browser-noise.ts       `test` and `expect` for the specs, with the noise guard
    browser-noise-rules.mjs  what is this app's, and the noise known elsewhere
  specs/                   one file per flow (see Coverage)
```

Selectors prefer stable hooks (NC `data-cy-*`, our own `data-testid`)
over localized text so specs survive UI-language changes. Content checks
usually go through the plugin's own HTTP endpoints + WebDAV rather than
the Etherpad API or editor typing; the author-display-name spec is the
one deliberate exception because it verifies the real Etherpad session UI.

### Browser noise

Specs import `test` and `expect` from `fixtures/browser-noise.ts`, not
from `@playwright/test`. It watches what the browser reports that no
assertion looked at: an error on the console, an exception nothing
caught, a request that failed outright, a `5xx`, or a script, stylesheet
or font that did not load. A flow can pass every assertion and still
leave one of these behind.

- A test fails on what comes from this app: its scripts, its routes and
  their answers, the srcdoc wrapper around the Etherpad frame, and the
  browser refusing to frame Etherpad. A console line goes by the script
  that logged it or the stack it logs, an exception by its stack, not by
  the words in them. Not the app's error: a request that met a dropped
  connection, a `502`/`503`/`504` that says `retryable` or is not the
  app's JSON (the client is built for both), a request aborted before the
  client's ten seconds were up.
- Everything else fails nothing. Nextcloud and its other apps log errors
  of their own on most pages; they are not this app's to fix, and would
  turn runs red with every release.
- What of that is known and explained (on Nextcloud 34: the Files service
  worker, the Viewer registering each handler twice, Text's rich
  workspace, a modal's focus trap) is on `KNOWN_ELSEWHERE` in
  `fixtures/browser-noise-rules.mjs`, each entry with why, and only
  counted, per entry, folded away in the summary. One that stays at 0
  can be struck.
- The rest goes into the test's report as `browser-noise`, and into
  `test-results/browser-noise.jsonl` for the whole run, which
  `global-setup.ts` starts empty. This app's own errors go into the
  report as `browser-errors-of-this-app`, also when the test failed for
  another reason first.
  `node tests/e2e/browser-noise-summary.mjs` groups it as Markdown; CI puts
  that on each job's summary page, so the noise a new Nextcloud release
  brings shows without downloading a report and without failing a test.
- Only a test with a browser context is watched; an API spec opens none.
  The record holds the attempt that counts, not each retry.
- `tests/js/e2e-browser-noise.test.js` holds the rules, the summary and
  every spec's import of `test` from the fixture.
- What a test causes on purpose it allows itself, with a reason:
  `browserNoise.allow('response', /\/pads\/open-by-id/, 'Etherpad is stopped on purpose')`.
- A context the test opens itself (`browser.newContext()`) is watched
  once the test hands it over: `browserNoise.watch(context)`, on the next
  line. The unit test holds every spec to it.
- A test that failed already reports nothing more; the guard would only
  bury the first error.

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
  recovery card and "Open the original" navigates to the source pad; so
  does such a copy after a trash + restore round-trip, which gives it no
  pad of its own.
- **pad-snapshot-roundtrip** — recover-from-snapshot pushes a known
  marker into a new pad and sync reads it back (the content copy that
  restore and recover share).
- **pad-trash-restore** — trash + restore round-trip, pad reopens.
- **pad-lost** — a pad Etherpad has lost (deleted there, or made anew empty
  by a visit to a public pad's address) answers `pad_missing`, a forced
  sync does not write a pad made anew over the file, and a new pad is made
  from the file's content, in the API and in the viewer; a file restored
  from the trash gets its new pad without asking.
  Container stack only (asks Etherpad).
- **pad-gone-for-good** — the trash keeps a pad and its group, a restore
  gives the same pad back; a pad goes with its file deleted for good:
  past the trash, from the trash once it is deleted there, with the
  account that owned it, a renamed file too; a pad in a team folder stays
  when its maker's account goes; the admin forgets a vanished public pad,
  its pad left in Etherpad, deletes one on its own, and - only where
  `E2E_THROWAWAY_STACK=1`, which the container stack's `up.sh` sets -
  deletes every vanished row of the instance, as many as counted. Container stack only (asks Etherpad);
  the team folder part needs groupfolders and skips without it. Creates
  and deletes throwaway accounts, groups and team folders.
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
required for these non-browser requests.

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
