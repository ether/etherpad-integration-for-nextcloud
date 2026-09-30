# Etherpad Nextcloud Plugin Architecture

SPDX-License-Identifier: AGPL-3.0-or-later

## Goal

The `etherpad_nextcloud` app integrates Etherpad for `.pad` files in Nextcloud with a native-viewer-first approach.
Etherpad is the editing source of truth; the `.pad` file acts as binding storage and snapshot container.

## Core Components

- `lib/Service/BindingService.php`
  - Manages the central DB table `ep_pad_bindings`.
  - Owns mapping `file_id <-> pad_id` and states (`active`, and `pending_delete` for a file deleted for good whose pad has yet to go).
  - Hands a row out as a `Binding`.
  - Only managed internal pads are bound. External pads are represented solely by `.pad` frontmatter and snapshots.
- `lib/Service/RestoreService.php`
  - A pad from the file's own snapshot where the file has none: back from the trash without a row, or with a pad Etherpad lost (`RestoreFromTrashListener`), and the recovery an open offers (`PadLifecycleController`; see Trash/Restore).
- `lib/Listeners/GoneFilesListener.php`
  - Marks the rows of files deleted for good (see "Files gone for good").
- `lib/Service/GoneFileSweep.php`, `lib/BackgroundJob/GoneFileSweepJob.php`
  - Every five minutes, deletes the pads of files deleted for good (see "Files gone for good").
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
  - Optional admin integrity scan: rows whose file is gone, and among them the vanished ones (see "Admin Integrity Check").
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
  - `state`: `active`, or `pending_delete` once the file was seen deleted for good (see "Files gone for good")
  - `deleted_at`: when the file was seen deleted for good; set only in `pending_delete`
  - `created_at`
  - `updated_at`: the last change, and for a row in `pending_delete` the last try at its pad
  - stores internal managed pads only; external `ext.*` rows from earlier development versions are removed by `Version000003Date20260512230000`
- `.pad` file
  - Frontmatter: format, binding metadata, state, export metadata.
  - Body: text and HTML snapshot.
  - For external pads, frontmatter (`pad_origin`, `remote_pad_id`, `pad_url`) is the source of truth and no DB binding exists.
- Snapshot helpers
  - `PadFileService::withExportSnapshot(...)` constructs updated `.pad` content for snapshot writes.
  - `PadFileLockRetryService::putContentWithSyncLockRetry(...)` persists that content to the Nextcloud file.
  - Stored snapshots are read by restore, by the forced-sync comparison, and by an open that may write and the sync to tell whether Etherpad has lost the pad (`ManagedPadLifecycle::howLost()`, `isMadeAnew()`); the read-only viewer no longer uses them at all (see `LivePadHtmlFetcher`).

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
6. An open that may write asks Etherpad whether it has lost the pad (`ManagedPadLifecycle::howLost()`), before any address or session. Each question waits three seconds at most - the revision count, and for a pad at revision 0 its text - so a slow Etherpad costs an open seconds, not the client's full timeout; a public pad needed no call to Etherpad to open before:
   - Lost is no pad under that id - for a protected pad, whose session would open nothing, or for a public pad whose file holds saved content: a snapshot past a pad's first revision (`snapshot_rev` above 0), or any text - a file made from a template by 1.1.0-beta.1 holds its content at `snapshot_rev: 0` - or one without a single revision while the file holds saved content, whose text is not the text the file saved: a public pad is reachable by its address, and Etherpad makes it anew when someone visits it, with its default text and the visitor as its author. A pad at revision 0 holding the saved text had its history cut short in Etherpad, and is not lost; one an admin made anew through the API with other text counts as made anew, and the recovery leaves it in place. So is a pad whose history was cut short after edits that never reached the file: it cannot be told from one made anew, so an open offers a new pad from the file and a restore makes one at once, while the pad with the newer text stays in Etherpad, its id in the log. Cutting a pad's history to revision 0 does not suit the file's revision count anyway: the sync goes by it. A public pad with nothing saved in its file, an Ownpad link to a pad nobody opened yet say, Etherpad makes on the first visit as ever. A pad merely behind the snapshot is not lost, since files a restore in 1.1.0-beta.1 left kept the old pad's revision count. Every way that seeds a new pad with content - a restore, a recovery, a pad made from a template - records the revisions seeding left as the file's `snapshot_rev` (`ManagedPadLifecycle::seed()`), so such a file counts as holding saved content from the start, before its first sync.
   - Only a definite answer stops the open (`ManagedPadLifecycle::isKnownLost()`): Etherpad slow, silent or refusing the question opens the pad as before, with a line at `debug`, and whatever is wrong with Etherpad shows there; any other fault of the check itself opens it too, with a line at `warning`, since the check is then not doing its job. A public pad whose file holds nothing saved is not asked about at all: it cannot be lost.
   - A lost pad answers `pad_missing` (`PadLostException`); the viewer and the embed page offer to make a new pad from the file's content (`POST /api/v1/pads/recover-from-snapshot/{fileId}`). That asks Etherpad again and takes the path a restore takes for a row whose pad is gone (`RestoreService::recoverFromSnapshot()` → `replaceLostPad()` → `restoreOntoNewPad()`): a new pad from the snapshot, the row moved onto it, the file naming it. The lost pad, or the empty one Etherpad made anew, is left alone. No other way of making a pad is added.
   - A reader, who could not make a new pad, is not asked, and is shown what the pad server has. A public share that may write answers the same, without the code: only the file's owner can make the new pad. The endpoint refuses whoever may not change the file with `403`, before Etherpad is asked.
   - What a replacement that fails leaves behind: "The replacement" under Trash/Restore.
   - Nor does a sync write a pad Etherpad made anew over the file: one without a single revision, with other text than the file saved, is refused with `pad_missing` (see "Sync"), and the file keeps its content for the new pad. Once someone writes into the pad made anew, a viewer already open when Etherpad lost the pad syncs on leaving as ever, and writes what Etherpad then has into the file: a pad holding a revision cannot be told from one merely behind the snapshot.
   - An older version of the file still names the lost pad, and opening it after the recovery is refused as a mismatch.
   - Logged once a minute for each file (`ApiErrorLog`), as a refusal is.

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
   - `epnc:create-failed` — payload `{reason, status, message, code, retryable}`. Fires on any error. `reason` is one of:
     - `'invalid'`: client-side validation failed; nothing was sent.
     - `'conflict'`: a `409`, a name taken or a file that changed while its pad was set up; `code` tells them apart.
     - `'server'`: refused with any other 4xx, by this app or by a proxy before it, so nothing was created; or failed by this app with a 5xx of its own, which it rolls back as far as it can; or answered in a way the page cannot use.
     - `'network'`: no answer from this app (see "Errors of the API"), so the pad may have been created anyway. `status` carries what came, if anything, and the message says to look in the folder before trying again.

     `code` and `retryable` are the server's (`docs/api-reference.md`), `null` and `false` without them: `retryable` says the same create may work later, a locked folder say.
   The create has no client-side time limit, as it writes: a slow create is not a failed one, and the page reports nothing until the server or a proxy answers. A host that stops waiting on its own must assume the pad may still be created.
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
   - A forced sync writes a pad behind the snapshot too when its content differs: files a restore in 1.1.0-beta.1 left kept the old pad's revision count, and only this brings them up to date. Not a pad Etherpad made anew in place of the file's - without a single revision, with other text than the file saved (`ManagedPadLifecycle::isMadeAnew()`, the rule the open goes by): its default text written over the file would leave the saved content to the file's versions, and the open would no longer find the pad lost and offer a new pad from the file. The sync answers `pad_missing` instead, as the open does, and writes nothing.
   - Snapshot writes are built via `PadFileService::withExportSnapshot(...)` and persisted via `PadFileLockRetryService::putContentWithSyncLockRetry(...)`.
   - Right before each write of a pad's snapshot - the ones after a wait for the file's lock too - its row is asked again (`BindingService::assertConsistentMapping()`, through `PadFileLockRetryService::putContentWithSyncLockRetry()`): a recovery may have moved it onto a new pad and written the file for it while Etherpad was asked, or while the sync waited, and the old pad's text written over that would leave file and row naming two pads.
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

- A trash leaves the pad as it is: the file keeps its row, active, and its pad, until the file is deleted for good (see "Files gone for good"). Nothing is written into the file. A public pad stays reachable by its address. A file in the trash is not synced, so its snapshot is the one from before the trash; while the pad lives, the pad holds the current content, and a restore brings it back. What each way of deleting a file does to its pad: [deleting-pads.md](deleting-pads.md).
- A delete - to the trash, or past it - takes the sessions of the protected pads it takes along (`RevokeSessionsOnDeleteListener`): a file's own row, or every active protected row under a folder, found before the delete (`BeforeNodeDeletedEvent`) by walking down the file cache's `parent` index one level at a time (`ProtectedPadsOfNode`), whatever the files are called - no walk at all when the instance has no active protected pad (an index on state and access mode answers that). The walk runs in the delete's request and stops past 100 pads or at 10,000 folders, with an `info` line: more than a delete can take the sessions of. Its queries are bounded too, so a folder with a hundred thousand others in it reads no more than the walk has room for. The sessions go once the delete is done (`NodeDeletedEvent`): one that fails, a file locked by a sync say, takes none. For each group, `PadSessionRevoker::revokeForPads()` lists its sessions first - most groups hold none that live, and then nothing else is asked - and takes the live ones only when the group holds that pad alone, or nothing (`ManagedPadLifecycle::groupHoldsOnly()`, the rule a pad's deletion goes by; all of a group's pads leaving together count as that): a legacy `.pad` may name someone else's group (see "Removing a pad" in `docs/etherpad-integration.md`). Group by group: a group's sessions go before the next group is asked, so a slow Etherpad spends the budget on the first group's deletes, not on listing them all. Within two seconds, as on a logout, and 100 deletes, the newest first: every open makes a session, so a pad opened often holds more than a cookie can, and the newest are those of whoever is at it now, and the last to expire. What does not fit expires on its own, and Etherpad refuses the next change of whoever had the pad open. A restore gives nothing back: the next open makes a new session. Nothing here stops the delete.
- A restore is heard of after the file is back, so a failure of the pad's step is logged and goes no further: the file is restored all the same, with its versions and without its trash entry, and its next open offers what the restore did not do.
- Restore of a file whose row stayed active - every file whose pad the trash kept: the pad is taken back as it is, unless Etherpad has lost it while the file was away (`ManagedPadLifecycle::howLost()`, see "Open (authenticated)"). Then a new pad is made from the file at once (`RestoreService::restoreActiveRow()`, the replacement below), where an open would only offer it: coming back from the trash, the file is surely the one the pad was. Etherpad not answering, or a file that cannot be read, leave the row to the next open, which offers the new pad should the pad be lost. A folder restored does not ask for each of its files: those an open finds lost offer a new pad.
- Restore without a binding row: a new pad from the file's snapshot, whatever `delete_pad_with_file` says: the trash of an earlier version took the pad along, and nothing is left to keep. Unless another file's row names the pad the file names, and that file is still there: then the file is a copy that was never opened, its pad lives on with the original, and the restore leaves it (`copy_of_another_file`). An original whose file is gone - deleted for good, its pad about to go, or vanished - leaves the copy the content, and the copy gets a pad of its own; its open offers the original or a new pad, as any copy's does. That includes the copy a share recipient's delete leaves in the recipient's trash, while the file itself goes to its owner's: restored, it is such a copy, and the pad stays with the owner's file. A file that cannot be read - a legacy Ownpad link without metadata, or one locked now - is left to its open, quietly (`file_unreadable`), as for an active row: the open migrates it, offers its recovery or says what is wrong.
- Restore of a file whose row is `pending_delete`: seen deleted for good, yet back, so the deletion did not happen. The row is active again, and the restore goes on as for any active row. An open of such a file does the same (`BindingService::assertConsistentMapping()`).
- The replacement: a new pad from the file's snapshot, the row moved onto it, the file naming it and recording its revision count. A pad behind the file - one without a single revision, with other text than the file saved - is left in place; whoever wrote into it has only that copy. Of a pad that does not exist, what is left - an empty group - goes, and only while it holds nothing: the pad may be back by then, made anew through the API, and a group that cannot be read stays.
  - Of two restores that race for one file only one writes it, and a restore that fails takes back what it made (`RestoreService::restoreOntoNewPad`).
  - A replacement that fails - Etherpad gone while the new pad is seeded, the file locked for the write - leaves the active row on the lost pad: moved back onto it when the row was already claimed. The next open offers the new pad again.
  - Seeding a new pad takes a while. Through WebDAV the file stays locked meanwhile, and a delete is refused (423, measured against NC 34.0.3); without that lock the file can be deleted again, or written. So the row is claimed only while the file is still where it was and still holds what the new pad was seeded from, read again right before: moved, and the new pad is let go (`file_moved`); written meanwhile - a sync of a pad made anew that someone wrote into, an upload - and it is let go too (`file_changed`), since written over, what was written would be gone. Nothing is claimed or written either way, and the next open asks again.
  - Only two things can leave a row naming a pad the file does not, and opening the file then answers `Binding pad ID mismatch.`: a database that fails again in the middle of the rollback, and a sync of a pad Etherpad made anew, written into after the recovery asked about it, that writes in the moment between its last look at the row and its write, just as the recovery moves the row (see "Sync"; a sync of a pad that is gone fails before it writes, and one of a pad made anew that nobody wrote into writes nothing). Neither the open nor the recovery settles that; nothing does yet.
  - A write that throws after the content landed - a hook after it failing - keeps the new pad: the file is asked which pad it names, and if it is the new one, row and file agree, and taking either back would leave them naming two pads. Logged at `warning`.
  - A write that throws on a file that then cannot be read either leaves open which pad the file names. No row may be left to contradict it: the row goes, and the file's next open finds no row and offers a pad from its content, whichever pad it names. The new pad stays, named in the `warning`: a write is not atomic on every storage, and one that broke off may have cut the file short, leaving the new pad the last whole copy of what the file held.
  - A row whose access mode is none the app knows gets no new pad, and stays as it is.
- A restore's pad step leaves these lines:
  - `Could not restore the pad of a file back from the trash. The file itself is restored.` at `error`, once for each way in that fails, `via` naming it (`hook` or `event`). A core restore passes twice (see Event Integration), so a line from the hook can be followed by an event pass that did what the hook pass could not.
  - `Legacy trashbin restore hook could not start.`, or `Legacy trashbin restore hook failed.` for anything the listener let through, at `error`.
  - `RestoreFromTrash listener skipped a restored node.` at `warning`, with its reason, when the restored node cannot be resolved.
  - A restore that leaves the pad alone on purpose says so at `debug` only, as `Lifecycle step skipped.` with its reason: a row whose access mode is unknown, a row another flow holds, an active row whose pad is there.
- Upgrading from 1.1.0-beta.1, whose trash deleted pads (`Version000005Date20260928120000`): a trash that could not reach Etherpad left its row `pending_delete`. One whose file is still in a trash, or back in Files, becomes an active row: its pad was kept, and the trash keeps it now. One whose file is gone for good stays `pending_delete` for the sweep. The setting `delete_on_trash` becomes `delete_pad_with_file`, with the value the admin gave it - and until then the old one's word stands, should this code run before its migration; read and written through `IAppConfig` alone, so the value keeps one type. Its three retry jobs (`Hot`, `Warm`, `ColdPendingDeleteRetryJob`) are taken off the job list, rather than left to the first cron run, which drops a job whose class is gone with a warning each, and the `test_fault` key its debug instances could set goes. The index on a row's state (`ep_bind_state_idx`) gives way to one on its state and access mode (`ep_bind_state_mode_idx`), which serves every question by state alone too, from its first column.
- External pads skip lifecycle side effects entirely. Trash/restore only affects the Nextcloud file; the remote Etherpad server is never mutated.

### 5b) Files gone for good

- A pad goes once its `.pad` file is deleted for good and gone from the file cache: from a trash, past it, or with the account that owned it. What each way of deleting a file does to its pad: [deleting-pads.md](deleting-pads.md).
- `GoneFilesListener` marks the rows of files seen deleted for good: `pending_delete`, dated by `deleted_at`. The file cache says what goes: Nextcloud reports every entry it removes (`CacheEntryRemovedEvent`), each descendant of a removed folder too, on 31 to 34. Not every removal is a deletion - a scan drops what vanished outside Nextcloud - so these count:
  - A removal from a trash: a user's (`files_trashbin/files/` on a home storage), a team folder's on the root storage (`__groupfolders/trash/`), or one on the folder's own storage (`trash/`, told from a folder of that name elsewhere by the storage's id). A folder of one of those names on any other storage - an external one, say - is a folder like another. That is the trash emptied, an item deleted there or expired, `occ trashbin:cleanup`, groupfolders' trash alike.
  - A removal of a node Nextcloud deletes, or of anything under it, between the node's `BeforeNodeDeletedEvent` and its `NodeDeletedEvent`: a delete past the trash (a WebDAV `DELETE` with `X-NC-Skip-Trashbin`, the trash app off for the user) takes the node and all under it. Where the node is - its storage and path in the file cache - is looked up as the delete starts, one query by its id. A delete that fails, a locked file say, raises no `NodeDeletedEvent`, and its window stays open for the rest of the process, a cron run's too; it covers the node's own entries only, never what a scan drops elsewhere. A delete that goes to a trash is a move. One within a storage removes nothing from the file cache, and one to another storage that keeps the id is reported as a removal and an insert (`CacheEntryInsertedEvent`), which makes the row active again. One to a storage whose cache is wrapped - an external storage with an encoding option, say - copies the file into the trash under new ids first and then removes the old ones: so an insert into a trash while a node is being deleted closes the delete's window, and what it removes then is not deleted for good. Not `MoveToTrashEvent`: the trash app sends it before it tries, and a delete it then does not take - an app vetoing it, the move failing - goes past the trash, puts nothing there, and counts. Likewise a restore from a user's trash (`BeforeNodeRestoredEvent` to `NodeRestoredEvent`, the window closed by the source's path, as the source has no id once restored): what it removes from the trash is not deleted for good.
  - A user deleted: every file on their home storage, which Nextcloud clears, file cache and all, without a single event. The files are looked up before (`BeforeUserDeletedEvent`), from the home mount (`IMountProviderCollection::getHomeMountForUser()`, as Nextcloud's own cleanup of a deleted user finds it, with no row in the mount cache needed), and marked once the user is gone (`UserDeletedEvent`). A deletion the user backend refuses raises no `UserDeletedEvent` and leaves the files unmarked.
  - What counts is the file's id, never its name: a `.pad` renamed keeps its row, and its pad goes with it.
  - What a scan drops never counts, in a trash or under a delete: `occ files:scan` says so (`NodeRemovedFromCache`) right before the entries go, and the listener leaves that entry and all under it, until the entry's own removal, which Nextcloud reports after all under it: a removal there later in the process counts again. The background scan and the watcher that checks for changes on access send no such word; what they drop in a trash, or under a delete whose window a failure left open, still counts.
  - Versions on a home storage (`files_versions/`) and app data on the root storage (`appdata_*`, previews among them) never count; a file of such a name elsewhere does. Every other removal of the instance comes by the listener too, so each costs a look at its path; one that counts takes a place in a set, written in blocks of 500 (`UPDATE ... WHERE file_id IN`) when full, when a delete through a node is done (`NodeDeletedEvent`, and `\OCP\Files::postDelete`, which a trash's deletes send too), and at the end of the process. A team folder's trash deletes at the storage, so what it removes waits for a full block or the end of the process; a process killed before - a long-running `occ background-job:worker`, say - loses it, and those pads stay, listed as vanished. Only files the file cache has nothing of by then are marked (`BindingService::markIfGone()`), so a removal reported under an id that is not the file's never marks a file that is still there. The rows are looked up first, so a removal of a file without one costs no look into the file cache, and the listener keeps only the files whose rows it marked: a cleanup over millions of entries holds a handful. The request does not touch Etherpad.
  - Nextcloud 34 reports a removed folder's descendants under the wrong ids - their places in a block of a thousand - and again with each block. That includes a whole trash emptied, which Nextcloud deletes as one folder (`files_trashbin`), and `occ trashbin:cleanup`. Fixed by nextcloud/server#63998 for 35.0.1; the backport to 34 (nextcloud/server#64497) is planned for 34.0.5 and not merged yet. It sends the block first (`CacheEntriesRemovedEvent`, from 34 on); a block holding id 0, which no file has, is such a block, and none of its removals counts, so no other file's row is marked. The listener hears the block ahead of other apps' (priority 100), so one throwing first - which Nextcloud catches before it sends the removals one by one - cannot keep it away; should the block not come, the removals under wrong ids still mark no file the file cache has, but they can mark the row of a file that vanished before. The files in a folder deleted for good there, or in a trash emptied as a whole, keep their pads and are listed as vanished. 31 to 33 report the right ids, one event each.
  - The listener never stops a delete and never throws: a mark that fails is a warning, and the row counts as vanished (below). One instance hears all of a request's events, as Nextcloud's container keeps the one it made.
- A row is due five minutes after it was marked (`BindingService::GONE_GRACE_SECONDS`): a margin before a step that cannot be undone, for an order of events no one has seen yet. A row is only marked once the file is gone from the file cache, a move keeps the file's entry, and Nextcloud never gives a removed id to a file again, so nothing known needs it.
- `GoneFileSweep` runs on every tick of a five-minute cron (`GoneFileSweepJob`, declared in `appinfo/info.xml`: an add at boot, as before, ran on every request and reset the job's last run; its interval is four minutes, as Nextcloud runs a job only once more than its interval has passed, and five would run it on every other tick), within 20 s (`RunBudget`): rows in `pending_delete` that are due and whose file the file cache has nothing of, the longest untouched first, in batches of 200, ten at most a run. A file deleted past the trash takes its pad within two runs of the job.
  - First, it makes the rows of files the file cache still has an hour after they were marked active again, 200 a run, with one `info` line: a deletion that did not happen after all - one rolled back, an account whose files were left. Kept waiting, such a row would take the pad of a file a scan drops later, which the app leaves. This runs with `delete_pad_with_file` off too.
  - Right before a pad goes, the file cache is asked once more, and the row goes only while it still waits and names the pad. A pad Etherpad no longer has counts as deleted, and its row goes. A pad Etherpad refuses to delete keeps its row, with a warning the first time, and is tried again an hour later (`updated_at`): it neither holds the head of the queue nor warns every run. Etherpad not answering ends the run with one `info` line. An error that reads as that - an HTTP error, an answer Etherpad could not have meant - may be the pad's alone, so Etherpad is asked the question it always can (`checkToken`) first: answered, the pad waits its hour like a refused one, and the run goes on.
  - A file marked but still in the file cache keeps its pad.
  - The admin page's settle (`POST /api/v1/admin/settle-pending`) runs the sweep too, within its budget, and takes the rows still in their five minutes and those Etherpad refused within the hour: what the job would do within minutes, or an hour, now - after the admin fixed Etherpad, say. A run tries each row once.
  - With `delete_pad_with_file` off no pad goes here, and the admin page's settle says so, but the rows keep waiting, so switching it on finds them.
- A file gone without being seen deleted is left alone, pad and row with it: one removed outside Nextcloud and dropped by a scan, a team folder deleted as a whole or a storage removed (Nextcloud clears their file cache without an event), a file cache rebuilt. The consistency check counts and lists such rows (`vanished_file_count`), and the admin can delete their pads from there (see "Admin Integrity Check"). A `.pad` file replaced by moving another file onto it through WebDAV goes to the trash first (measured against NC 34.0.3), so its pad goes once it leaves the trash.

### 6) Admin Integrity Check (optional)

1. Admin runs `POST /api/v1/admin/consistency-check`, from the admin page or the API; nothing runs it on its own.
2. It counts the vanished rows (`vanished_file_count`): their file gone from the file cache, still active, never seen deleted for good. Those are the files gone without a deletion the app heard of - from a trash, past it, or with an account; their pads stay (see "Files gone for good"), and they are what the check reports as issues. A row seen deleted for good is on its way and not counted: `pending_delete`, which the sweep takes within minutes, or keeps while `delete_pad_with_file` is off (`pending_delete_count` in the health check).
3. It returns up to 25 of them (`samples`). The admin page lists them by pad id and access mode, with how many there are.
4. Its cost grows with the rows, or with the file cache, whichever the database reads: measured at 0.14 to 0.35 s for 500,000 rows and 2 million files (Postgres 16, warm).
5. The admin can delete their pads, from the admin page after a confirmation: one listed file's (`POST /api/v1/admin/delete-vanished`, `ConsistencyCheckService::markVanishedFile()`), or all of them (`POST /api/v1/admin/delete-all-vanished`, `markVanished()`) - as many as the count the admin confirmed, and none if the list no longer has that count. Their rows are marked as files deleted for good (`BindingService::markGone()`, which counts what its update changed), and the sweep takes their pads as any other, asking the file cache once more first; an `info` line says it was the admin's word. For all of them the ids are collected once, up to 500,000 a call - the query reads the whole file cache whatever its limit - and that one query also says whether they are as many as confirmed: counted by another, the list could grow in between, and the limit would take a file the admin was not shown. They are marked 500 at a time within a sweep's budget, and what was marked is logged whatever ends the run, a failing chunk too: the queries measured at 0.27 s for the ids and 3.7 s for the marks of 500,000 rows among 2 million files (Postgres 16, warm). Each answer carries the list as it is after the action, at the cost of the check itself. With `delete_pad_with_file` off nothing is marked. A mark cannot be taken back from the page. Deleting such a pad in Etherpad alone leaves its row on the list.
6. Or the admin forgets one listed public pad (`POST /api/v1/admin/forget-vanished`, `ConsistencyCheckService::forgetVanished()`): its row goes, the pad stays in Etherpad, named in an `info` line. The file cache is asked once more after the row went, since no sweep checks a forgotten row again: a file it has after all gets its row back. Why only a public pad, why the app does neither on its own, and what a forgotten pad is left as: `docs/deleting-pads.md`.

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

## Errors of the API

- `PadControllerErrorMapper` (signed in) and `PublicViewerControllerErrorMapper` (public shares) answer an error with a translated sentence of their own; `code` and `retryable` come from `ApiErrorCode`, and `docs/api-reference.md` lists the codes. An exception's message is for the log; only a refusal translated where it is thrown, and the reason a pad on another server cannot be linked or read, reach the reader as they are.
- The viewer and the embed page offer "Try again" where the same open may work later (`isRetryableOpenError()` in `src/lib/pad-open-flow.js`); the button runs the whole open again. That is every answer with `retryable` (`docs/api-reference.md` lists the cases); `pad_file_changed`, which an open meets only while initialising the file, after the server undid its part; and any step of the open that got no answer, the initialise too. The second try opens first and finds a pad the first try set up, and one still being set up is safe to meet: the server compares the file before it writes, a file has one binding row, and a pad that lost either race is rolled back.
- `fetchJsonWithTimeout()` marks a request that got no answer from this app as `unanswered`: its own timeout, a failed network, also while the body streams in (the status, if one came, goes along), and a 5xx that cannot be this app's answer by what `docs/api-reference.md` says of its errors: one whose body is not JSON, or a 502, 503 or 504 without `retryable`. A proxy, Nextcloud in maintenance or PHP dying midway sent it. It says only that, since whether another try is safe depends on the request. A recovery that got no answer is not offered again: the clients open the file instead, which shows the pad if it went through and the recovery card if not. Both clients say "no answer" in a translated sentence of their own rather than the browser's English.
- After a click whose button goes away or is disabled with the focus on it, a second try or a recovery, the viewer and the embed page hand the focus to the card's first action, or to its message (`src/lib/hand-focus.js`). Not on the first load. The embed page does it without scrolling, since it sits in another page. The message of each error card is `role="alert"`, so an error is read out when it appears, the first one too.
- This instance's Etherpad not reachable answers `503` with `retryable`; a refusal from it (`EtherpadRefusedException`: a pad or group it does not have, a key it does not take) `400`. A pad on another server (`ExternalPadException`) is neither. Which is which the exceptions say (`EtherpadClientException::isEtherpadUnreachable()`); the answer and the log both go by it.
- Each error answered is logged once, by `ApiErrorLog`; the services under the mappers leave it to it, save the few lines that explain a refusal nothing else would (a name another create has locked, a file a create will not write over, a refused legacy migration):
  - Etherpad not reachable: `Etherpad could not be reached while answering a request.`, a warning once a minute for the instance and at debug for the rest of that minute, since an outage reaches every open viewer's sync. Refusing: `Etherpad refused a request.`, once a minute for each file, since each is a case of its own (a pad deleted in Etherpad, a group gone), and once a minute for all refusals that name none. Without a distributed cache, or with one that fails, each is a warning.
  - A `.pad` and its row that do not match (`BindingMismatchException`): `A .pad file and its pad binding could not be matched.`, a warning. A row that could not be written (`BindingNotCreatedException`): `Could not create pad binding.`, an error with the database's cause.
  - The unforeseen: an error under the endpoint's line (`Pad recovery API failed`, `Pad sync failed`, ...), or `Unhandled pad controller error` / `Unhandled public viewer error`.
  - Anything else - what the request got wrong, a pad on another server: `A request was refused.` at debug, with the reason the answer leaves out.
  - The line names the request's file (`ApiErrorLog::fileNamedBy()`): signed in by `fileId` and `file`, on a public share by `fileId` only, since a public path may be a DAV URL carrying the share token.

## Event Integration

- `OCA\Files\Event\LoadAdditionalScriptsEvent`
  - Register the viewer handler before Files initializes.
- `OCA\Viewer\Event\LoadViewer`
  - Register the viewer handler on other pages that load Viewer.
- `OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent`
  - Register the viewer handler on public-share pages and load the one-shot opener for public single-file `.pad` shares or existing compatibility links.
- `OCP\Files\Cache\CacheEntryRemovedEvent`, `OCP\Files\Cache\CacheEntryInsertedEvent`, `OCP\Files\Cache\CacheEntriesRemovedEvent` (from 34), `OCP\Files\Events\Node\BeforeNodeDeletedEvent`, `OCP\Files\Events\Node\NodeDeletedEvent`, `OCP\Files\Events\NodeRemovedFromCache`, the event named `\OCP\Files::postDelete`, `OCA\Files_Trashbin\Events\BeforeNodeRestoredEvent`, `OCA\Files_Trashbin\Events\NodeRestoredEvent`, `OCP\User\Events\BeforeUserDeletedEvent`, `OCP\User\Events\UserDeletedEvent`
  - Mark the rows of files deleted for good (see "Files gone for good").
- `OCP\Files\Events\Node\BeforeNodeDeletedEvent`, `OCP\Files\Events\Node\NodeDeletedEvent`
  - Take the sessions of the protected pads a delete takes along (see Trash/Restore).
- `OCP\User\Events\UserLoggedOutEvent`, `OCP\User\Events\BeforeUserDeletedEvent`
  - Take the account's own sessions: on a logout, and as the account's delete starts, while Nextcloud still has its Etherpad author (see `docs/etherpad-integration.md`, "Session lifetime and revocation").
- `OCA\Files_Trashbin\Events\NodeRestoredEvent`
  - Restore lifecycle.
- `\OCA\Files_Trashbin\Trashbin::post_restore` (legacy hook)
  - Restore lifecycle for groupfolders, which fires no `NodeRestoredEvent`. Core fires both, the hook first, to the same listener: the event pass is a second try at what the hook pass could not do - after a hook pass that failed on the database, or could not read the file. What the hook pass decided - the pad restored, made anew, or left as it is - the event pass leaves, rather than ask Etherpad the same again; so it does after a hook pass that asked Etherpad and got no answer, or an error, so that a restore asks an Etherpad that hangs once, not twice. Every hook pass starts afresh.
