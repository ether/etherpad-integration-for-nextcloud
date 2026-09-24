# Etherpad Nextcloud Plugin Architecture

SPDX-License-Identifier: AGPL-3.0-or-later

## Goal

The `etherpad_nextcloud` app integrates Etherpad for `.pad` files in Nextcloud with a native-viewer-first approach.
Etherpad is the editing source of truth; the `.pad` file acts as binding storage and snapshot container.

## Core Components

- `lib/Service/BindingService.php`
  - Manages the central DB table `ep_pad_bindings`.
  - Owns mapping `file_id <-> pad_id` and states (`active`, `pending_delete`, `restore_pending`).
  - Hands a row out as a `Binding`, and a row the sweep takes as a `WaitingBinding` with its file's path; the sweep asks for deletions owed by `FileLocation`.
  - Only managed internal pads are bound. External pads are represented solely by `.pad` frontmatter and snapshots.
- `lib/Service/LifecycleService.php`
  - Where a `.pad` file's trash and restore arrive, from the listeners and the API; the trash is done here (see Trash/Restore).
- `lib/Service/RestoreService.php`
  - The restore: from the trash, in the sweep, and for a file without a row (see Trash/Restore).
- `lib/Service/TrashSnapshotWriter.php`
  - The snapshot into a trashed file, at trash time and in the sweep, made by `TrashSnapshotWriters`: one file, one pad, and the reason when it does not get there (`TrashSnapshotMiss`). What a miss means for the row and the pad is its caller's.
- `lib/Service/PendingBindingService.php`
  - The sweep: settles rows that wait, by where their file is now, bounded per run by `RunBudget` (see Trash/Restore). It writes only into trashed files, and deletes only the pads of files in a trash or gone for good.
- `lib/Service/OwedDeletions.php`
  - The deletions a trash owed, for the sweep: a trashed file's snapshot, then row and pad; for a file gone for good, pad and row.
- `lib/BackgroundJob/*PendingDeleteRetryJob.php`
  - Bucketed runs of `PendingBindingService`: `restore_pending` rows aged by `updated_at`, `pending_delete` rows by `deleted_at`. Named for what they did first; the job list stores the class name.
    - hot rows: every 5 minutes for the first hour
    - warm rows: hourly from 1h to 24h
    - cold rows: daily after 24h
- `lib/Service/EtherpadClient.php`
  - Adapter for Etherpad HTTP API (pad create/delete/session/read-only/export).
- `lib/Service/PadFileService.php`
  - Parser/serializer for `.pad` v1 (YAML + snapshot body).
  - Revision metadata and snapshot body structure (`[TEXT]`, `[HTML-BEGIN]`, `[HTML-END]`).
- `lib/Service/PadSessionService.php`
  - Session-cookie flow for protected GroupPads.
- `lib/Service/PadTypePolicy.php`
  - Which pad types the instance offers (both enabled by default).
  - Guards creation only; existing pads of a disabled type keep working.
  - Resolves a template's access mode to an enabled one instead of failing.
- `lib/Service/ConsistencyCheckService.php`
  - Optional admin integrity scan:
    - bindings without file
    - `.pad` files without binding
    - invalid/mismatching frontmatter on bound files
- `lib/Controller/ViewerController.php`
  - Compatibility redirect adapter:
    - resolves `.pad` path/id to stable Nextcloud files viewer URL.
- `lib/Controller/PublicViewerController.php`
  - Public-share API + compatibility redirect adapter (`/public/{token}` -> `/s/{token}` with file selection).
- `lib/Controller/EmbedController.php`
  - Minimal embed entrypoints for trusted same-site / trusted-origin integrations.
  - Renders blank embed/open and embed/create pages with route-specific CSP `frame-ancestors`.
- `lib/Controller/PadCreateController.php`, `lib/Controller/PadSessionController.php`, `lib/Controller/PadLifecycleController.php` (all extend `AbstractPadController` for the shared deps + helpers)
  - The `.pad` API surface split along three concerns: create-side endpoints, open/init/meta endpoints, and lifecycle/sync endpoints. Public URL paths (`/api/v1/pads/…`) are stable; only the internal controller class differs per route.
  - For protected pad opens, `PadSessionController` attaches the explicit Etherpad `Set-Cookie` session header via the response.

## Frontend Build

Frontend code is authored as ES modules in `src/` and built with Vite into
checked-in runtime assets in `js/`.

- Build entrypoints are defined in `vite.config.js`:
  - `src/viewer-init.js`
  - `src/public-share-main.js`
  - `src/embed-main.js`
  - `src/embed-create-main.js`
  - `src/admin-settings.js`
- Shared browser/Nextcloud helpers live in `src/lib/`.
- Nextcloud loads the viewer registration with `Util::addInitScript(...)` before the Viewer starts. The handler carries the component itself rather than a loader for it: the Viewer assigns its Mime mixin onto what it is given and registers it under `component.name`, and a function takes neither.
- `@nextcloud/viewer` 1.x declares a Vue 2 peer although its registration binding imports no Vue. The narrow `package.json` override lets that framework-free binding coexist with the Vue 3 peer used by the build tooling without weakening npm's handling of unrelated peers.
- Blank embed templates load their built bundles explicitly.
- After editing `src/`, run `npm test` and `npm run build` before deployment.

## Persistence Model

- DB table `ep_pad_bindings` (migration: `lib/Migration/Version000001Date20260304222000.php`)
  - `file_id`
  - `pad_id`
  - `access_mode`
  - `state`
  - `deleted_at`
  - `created_at`
  - `updated_at`
  - stores internal managed pads only; external `ext.*` rows from earlier development versions are removed by `Version000003Date20260512230000`
- `.pad` file
  - Frontmatter: format, binding metadata, state, export metadata.
  - Body: text and HTML snapshot.
  - For external pads, frontmatter (`pad_origin`, `remote_pad_id`, `pad_url`) is the source of truth and no DB binding exists.
- Snapshot helpers
  - `PadFileService::withExportSnapshot(...)` constructs updated `.pad` content for snapshot writes.
  - `PadFileLockRetryService::putContentWithSyncLockRetry(...)` persists that content to the Nextcloud file.
  - Stored snapshots are read only by restore and by the forced-sync comparison; the read-only viewer no longer uses them at all (see `LivePadHtmlFetcher`).

## Main Flows

### 1) Create

1. `PadCreateController::create` creates an Etherpad pad (public or protected/group). `PadCreationService` asks `PadTypePolicy` first and refuses a pad type the admin switched off; the check sits in the create paths rather than in `PadBootstrapService::provisionPadId`, which also serves existing files.
2. Creates the `.pad` file.
3. Writes initial frontmatter.
4. Creates DB binding.
5. External create-from-URL is different: it only creates the `.pad` file with external frontmatter plus an optional text snapshot; it does not create or own anything on the remote Etherpad server.

### 2) Open (authenticated)

Primary flow (native viewer):

1. On an authenticated Files route, Nextcloud's own Viewer action opens the file: `src/viewer-init.js` registers the `.pad` MIME type through `@nextcloud/viewer` before the Viewer starts, and the app contributes no file action of its own.
2. `src/viewer-main.js` resolves Etherpad open data via API:
   - preferred: `POST /api/v1/pads/open-by-id` (`fileId`, CSRF `requesttoken`)
   - fallback: `POST /api/v1/pads/open` (`file`, CSRF `requesttoken`) if no stable `fileId` is available
3. `PadSessionController` validates frontmatter/binding and resolves secure open URL:
   - `protected`: session URL via `PadSessionService`
   - external: validate the stored external URL and return a read-only snapshot/open target without DB binding lookup
   - `public`: direct/read-only URL as appropriate
4. For protected pads, response includes one Etherpad session `Set-Cookie` header.
5. Legacy app routes (`/apps/etherpad_nextcloud`, `/by-id/{fileId}`) redirect into the same native files viewer URL.

### 2b) Open (trusted embed integration)

Primary flow (minimal blank embed page):

1. External same-site / trusted-origin host loads `GET /apps/etherpad_nextcloud/embed/by-id/{fileId}` inside an iframe.
2. `EmbedController::showById` validates:
   - logged-in Nextcloud user
   - accessible `.pad` file by stable `fileId`
3. `templates/embed.php` loads the Vite-built bundle for `src/embed-main.js` explicitly because blank layouts do not rely on Nextcloud asset collector injection.
4. `src/embed-main.js` calls `POST /api/v1/pads/open-by-id` same-origin with CSRF token baked into the template.
   - because blank layout does not inject the normal `OC.requestToken` bootstrap
   - and this Nextcloud version exposes no public `OCP\...` CSRF-token service for that template use-case
   - `EmbedController` therefore passes the encrypted token manually from the internal CSRF token manager
5. On `code: missing_frontmatter`, the embed page retries once after `POST /api/v1/pads/initialize-by-id/{fileId}`.
6. As soon as `open-by-id` returns `url`, the iframe `src` is set to the Etherpad target.
7. Sync and host-message handlers are installed after iframe start so initial visual load is not delayed by background setup.

Trusted host integration details:

- Embed routes use route-specific `frame-ancestors` from admin setting `trusted_embed_origins`.
- `src/embed-main.js` accepts host messages only from:
  - `window.location.origin`
  - configured trusted embed origins
- Supported host messages:
  - `epnc:host-visible`
  - `epnc:host-hidden`
  - `epnc:host-before-close`
  - `epnc:host-sync-now`
- Close handshake:
  - host sends `epnc:host-before-close`
  - embed replies with `epnc:sync-flush-started`
  - then `epnc:sync-flush-finished` or `epnc:sync-flush-failed`
  - host should wait briefly for that ack before unmounting the iframe

### 2c) Create (trusted embed integration)

Primary flow (minimal blank create launcher page):

1. External same-site / trusted-origin host loads `GET /apps/etherpad_nextcloud/embed/create-by-parent/{parentFolderId}?name=...&accessMode=...`.
2. `EmbedController::createByParent` validates:
   - logged-in Nextcloud user
   - writable target folder by stable `parentFolderId`
3. `templates/embed-create.php` loads the Vite-built bundle for `src/embed-create-main.js` explicitly in blank layout.
4. `src/embed-create-main.js` reads `name` and `accessMode` from the launcher URL, validates them client-side, and calls `POST /api/v1/pads/create-by-parent` same-origin with CSRF token from the template.
   - the token is injected manually for the same reason as embed-open: blank layout has no automatic `OC.requestToken` bootstrap
5. `PadCreateController::createByParent` performs server-side validation of `name`, `accessMode`, and the writable target folder before creating the `.pad` file and binding.
6. Before redirecting, `src/embed-create-main.js` posts the host page one of two structured events so the surrounding UI can react without scraping the iframe DOM:
   - `epnc:create-succeeded` — payload `{embed_url, file_id, pad_id, access_mode}`. Fires once on the success path, immediately before the iframe self-redirects to the embed-open URL.
   - `epnc:create-failed` — payload `{reason, status, message}`. Fires on any error. `reason` is one of `'invalid'` (client-side validation), `'conflict'` (HTTP 409 — e.g. duplicate filename), `'server'` (any other 4xx/5xx), or `'network'` (fetch itself failed).
   The inline error rendering inside the iframe is unchanged — `postMessage` is purely additive for hosts that want to act on the outcome. Target-origin is `*` because the page doesn't know the host's origin up-front; the `frame-ancestors` allowlist already constrains who can be the parent.
7. On success the launcher redirects itself to the returned `embed_url`, after which the normal embed-open flow takes over.

### 3) Open (public share)

Primary flow (native viewer):

1. Public share routes stay on Nextcloud share URL (`/s/{token}`).
2. Public folder shares use Nextcloud Files Sharing's own file action. A public single-file `.pad` share explicitly opens `/`, the share root, through the Viewer after registration. Existing app compatibility links hand their `path` and `files` selection to the Viewer once after the redirect.
3. `src/viewer-main.js` detects public share context and resolves open data via:
   - `GET /api/v1/public/open/{token}?fileId=...` where the Viewer knows an id, `?file=...` otherwise - one locator, never both. See "Naming the file in a public share" in `docs/api-reference.md`.
4. Same open-target rules apply:
   - read-only share: Etherpad read-only URL
   - editable share: regular URL/session
5. For protected share-open flows, session bootstrap uses one explicit `Set-Cookie` header.
6. Compatibility route `/apps/etherpad_nextcloud/public/{token}` redirects to native share route `/s/{token}`.

## Cookie Header Model

- Cookie construction is centralized in `PadSessionService` (`buildSetCookieHeader()`).
- We intentionally use explicit attributes required for Etherpad iframe sessions across subdomains:
  - `Domain`
  - `Secure`
  - `SameSite=Lax`, or `None` where `etherpad_session_cookie_samesite` says so
- Domain source:
  - explicit `etherpad_cookie_domain` app setting when configured
  - otherwise derived from `etherpad_host` with label-aware fallback rules
  - explicit config is recommended for proxy-heavy or non-standard subdomain setups
- Current app-level contract:
  - one custom Etherpad `Set-Cookie` line per protected-open response
  - no additional app-level custom cookies on these same responses
- If we later need multiple custom cookies on the same response, header handling must be extended as a dedicated change (with targeted controller tests), because multi-`Set-Cookie` behavior is a framework-sensitive edge case.

### 4) Sync

1. Frontend (`src/viewer-main.js`) triggers periodic sync while a pad is open in native viewer.
2. Trusted embed flow (`src/embed-main.js`) runs the same snapshot sync contract:
   - interval sync while visible
   - flush on `visibilitychange`
   - flush on `pagehide`
   - extra flush triggers via trusted host messages
3. `PadLifecycleController::syncById` fetches revision state from Etherpad.
4. `.pad` snapshot is updated only when the upstream snapshot actually differs.
   - `force=1` requests an immediate upstream re-check, but unchanged snapshots are still not rewritten.
   - Snapshot writes are built via `PadFileService::withExportSnapshot(...)` and persisted via `PadFileLockRetryService::putContentWithSyncLockRetry(...)`.
5. External pads are synced as text only (no HTML import).
   - They are selected by `.pad` frontmatter, not by `ep_pad_bindings`.
6. Write-lock handling:
   - short bounded retry around `.pad` snapshot writes (`150ms`, `300ms`, `600ms`)
   - if still locked, API returns `status=locked` and `retryable=true`
7. Revision-based status check is still exposed for programmatic use:
   - `GET /api/v1/pads/sync-status/{fileId}` compares `snapshot_rev` with `current_rev`.
   - `POST /api/v1/pads/sync/{fileId}?force=1` can be invoked to trigger an immediate snapshot write.
   - There is no UI affordance for either; the viewer drives sync automatically.

### 5) Trash/Restore

- When the pad's step fails - an exception, the database gone, say - the file operation stops only while stopping still protects something. A trash is decided before the file moves (`MoveToTrashEvent`), so such a failure stops the delete and the file stays where it was; a pad Etherpad cannot delete is not one, its deletion is owed instead (see below). A restore is heard of after the file is back, so a failure there is logged, and the sweep or the file's own recovery takes up the pad.
- Trash: write a fresh snapshot into the file, then delete the managed Etherpad pad and the binding row. A file that already holds the pad's revision is not written again. A snapshot is never older than the file's: a pad behind the file's `snapshot_rev` (created again under its id, or back from an older backup) is not written into it, and its deletion is owed, for the sweep's rule on such a pad. After a write the pad is counted once more; if it moved on while the file was written, it is not deleted, and the sweep writes another snapshot. Etherpad cannot hold a pad still, so an edit in the moment between that count and the delete is still lost; the count keeps the write, the slowest step, out of that moment.
- A file that cannot be read, for any reason, is one more way to have no fresh snapshot: the user's delete goes through.
- No fresh snapshot, or the delete fails: the pad stays as it is and the row becomes `pending_delete`; Nextcloud's trash succeeds either way. A delete through WebDAV (Files UI, clients) holds the file's lock while the trash is decided, so there it is always this path. The sweep finishes the trash on its next run, usually within five minutes: the pad's content goes into the trashed file, then row and pad go (see below). Until then a public pad stays reachable by its URL, a restore takes the pad back, and the admin page counts it as a pending delete.
- Restore without a binding row: provision a new pad from `.pad` frontmatter/snapshot.
- Restore of a waiting row (`pending_delete` or `restore_pending`), whatever `delete_on_trash` says now: read the file's `snapshot_rev`, then ask Etherpad about the row's pad. The pad id comes from the row, never from the file.
  - It exists with at least that many revisions: the row becomes `active` again on that same pad, which may hold edits the snapshot missed. If the sweep took row and pad in the meantime, finishing the trash, the restore finds no row when it reads again and makes a new pad from the file, which holds the snapshot the sweep wrote first.
  - Etherpad answers that it does not exist, or it has fewer revisions (created again since, or back from an older backup): a new pad from the file's snapshot, and the file records the new pad's revision count. A pad with fewer revisions is left in place and logged before anything else is tried; whoever wrote into it has only that copy.
    - Of two restores that race for one file only one writes it, and a restore that fails takes back what it made (`RestoreService::restoreOntoNewPad`).
  - No answer, or the file cannot be read: `restore_pending`, and neither pad nor file is touched. A row that waits again moves to the back of the queue (`updated_at`).
- A restore whose pad's step fails still restores the file, with its versions and without its trash entry; the failure goes no further than the log. A pad left unrestored shows there as one of these lines:
  - `Could not restore the pad of a file back from the trash. The file itself is restored.` at `error`, once for each way in that fails, `via` naming it (`hook` or `event`). A core restore passes twice (see Event Integration), so a line from the hook can be followed by an event pass that restored the pad after all.
  - `Legacy trashbin restore hook could not start.`, or `Legacy trashbin restore hook failed.` for anything the listener let through, at `error`.
  - `RestoreFromTrash listener skipped a restored node.` at `warning`, with its reason, when the restored node cannot be resolved.
  - A restore that leaves the pad alone on purpose says so at `debug` only, as `Lifecycle step skipped.` with its reason: `delete_on_trash` off for a file without a row, a row whose access mode is unknown, a row another flow holds.
- What the pad's restore left unfinished waits for the sweep, or the file offers its own recovery. Opened meanwhile, a file whose row waits says the pad is still being restored and offers to try again (`waiting_binding`). Only a database that fails again in the middle of the rollback can leave a row naming a pad the file does not; opening the file then answers `Binding pad ID mismatch.` A restore through the API (`POST /api/v1/pads/restore`) still answers such a failure with an error.
- `PendingBindingService` settles waiting rows in age buckets (every 5 minutes, then hourly, then daily) and from the admin page, by where the file is now:
  - in Files (`restore_pending`, or `pending_delete` whose restore never came): the decision a restore takes, except that a sweep writes no file. A pad that is gone or behind releases the row, and the file offers its own recovery when it is next opened.
  - in its owner's trash (`pending_delete`): the rest of the trash, written on the owner's own storage. A pad past the file's `snapshot_rev` goes into the file (one at it is there already), then row and pad are deleted; a restore that comes first keeps the pad. A pad that is gone: the row goes, and for a group pad what is left of it (an empty group). A pad that is behind is logged with its id and left in place, and the row goes. Either way the file keeps the snapshot it has. A pad that cannot be deleted once its row is gone is left over and logged with its id; its content is in the file.
    - A file that did not get its snapshot waits for the next run, in one log line, `A trashed .pad file did not get its snapshot`, with a `reason` (`TrashSnapshotMiss`). The file's own trouble - unreadable, unparsable, a write refused, an empty file - moves the row to the back and is reported at warning level the first time only. What passes by itself - a lock, a pad that changes while it is read, a file a restore took back - is tried again where the row is, at debug level. A row a trash before 1.1.0 wrote may report at debug level from the start; a row without `deleted_at` reports each time.
    - An empty file waits until its trash lets it go: there is nothing to write a snapshot into, and it says nothing about the pad, which may hold the only copy.
  - in a team folder's trash: nothing yet; the row waits until that trash lets the file go. On the root storage (`__groupfolders/trash/`) such rows are not fetched at all. A team folder with its own storage keeps trashed files under a bare `trash/`, found by no node, and the row moves to the back. With groupfolders' `auto` retention and no quota the trash never lets the file go, and a public pad stays reachable until it is emptied.
  - gone for good, with no `filecache` row left, in either waiting state: pad deleted, then row. No restore can come for such a file, and a pad that could not be deleted keeps its row for the next run.
  - While `delete_on_trash` is off, no pad is deleted here, even when it is switched off during a run: rows in a trash or gone for good wait, and are not fetched.
  - A run takes rows of each kind in turn: restores left undecided, then deletions owed for a file gone for good, in a user's trash, and anywhere else. Each kind is asked for the whole limit and what one leaves goes to the others, so rows no run can settle yet crowd out only rows of their own kind, and a run that ends on its budget has reached every kind. Within a kind, rows are aged by `deleted_at` (`updated_at` for `restore_pending`) and taken by `updated_at`: a row that waits for its file's own trouble, or whose file no node reaches, moves to the back.
  - A row is settled by one run at a time: the jobs and the admin page can run at once, and each holds a lock on the row (`etherpad_nextcloud:settle:<fileId>`), which a process that dies holding it leaves until it expires, an hour with the database lock.
  - A run lasts up to 20 s (`RunBudget`). A row Etherpad gives no answer for stays where it is, and after five of them the run stops; a file that cannot be read does not count. A pad whose row is already gone is deleted even at the end of a run, on the client's own timeouts.
- Trashing a file whose row is `restore_pending` returns the row to `pending_delete`, whatever `delete_on_trash` says, and leaves the pad alone, without a snapshot: which pad is the file's is what is undecided. If a sweep settled the row a moment earlier, the trash reads it again and trashes the file as what it is now.
- `deleted_at` is set only in `pending_delete`, when a row gets there, and kept while it stays.
- External pads skip lifecycle side effects entirely. Trash/restore only affects the Nextcloud file; the remote Etherpad server is never mutated.

### 6) Admin Integrity Check (optional)

1. Admin runs `POST /api/v1/admin/consistency-check`.
2. Service scans DB/file metadata consistency.
3. Returns aggregate counters and bounded sample lists for diagnostics.
4. External `.pad` files without bindings are expected and are excluded from missing-binding diagnostics.

## Main Frontend Modules

- `src/viewer-init.js`
  - Registers the MIME handler synchronously through `@nextcloud/viewer`.
  - Supplies `src/viewer-main.js` as the handler's component.
- `src/public-share-main.js`
  - Opens the root file on public single-file `.pad` shares with the native Viewer context.
  - Hands existing public folder-share links with `path` and `files` to that Viewer once; normal folder navigation remains native.
- `src/viewer-main.js`
  - Open URL resolution via CSRF-protected `POST` endpoints:
    - `open-by-id` (preferred)
    - `open` (fallback)
  - Handles initialize-retry when frontmatter is missing.
  - Triggers periodic/unload-safe sync loop for authenticated native viewer sessions.
- `src/embed-main.js`
  - Powers the minimal `/embed/by-id/{fileId}` page for trusted host integrations.
  - Same-origin open flow via `open-by-id` and optional `initialize-by-id` retry.
  - Sets iframe `src` as early as possible, then starts sync/host handlers.
  - Implements trusted host message contract and close-flush ack protocol.
- `src/embed-create-main.js`
  - Powers the minimal `/embed/create-by-parent/{parentFolderId}` launcher page.
  - Same-origin create flow via `POST /api/v1/pads/create-by-parent`.
  - Redirects to returned `embed_url` after successful creation.
- `src/lib/*`
  - Shared constants, URL builders/parsers, Nextcloud runtime helpers, OC compatibility helpers, DOM helpers, and API client code.

## Event Integration

- `OCA\Files\Event\LoadAdditionalScriptsEvent`
  - Register the viewer handler before Files initializes.
- `OCA\Viewer\Event\LoadViewer`
  - Register the viewer handler on other pages that load Viewer.
- `OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent`
  - Register the viewer handler on public-share pages and load the one-shot opener for public single-file `.pad` shares or existing compatibility links.
- `OCA\Files_Trashbin\Events\MoveToTrashEvent`, and the legacy event `OCA\Files_Trashbin::moveToTrash`
  - Trash lifecycle.
- `OCA\Files_Trashbin\Events\NodeRestoredEvent`
  - Restore lifecycle.
- `\OCA\Files_Trashbin\Trashbin::post_restore` (legacy hook)
  - Restore lifecycle for groupfolders, which fires no `NodeRestoredEvent`. Core fires both, the hook first: once the first pass has restored the pad the second finds nothing left to do, and after a first pass that failed, or left the row waiting for Etherpad's answer, it tries again, asking Etherpad a second time.
