# API Reference

SPDX-License-Identifier: AGPL-3.0-or-later

## Nextcloud App Routes

Base: `/apps/etherpad_nextcloud`

- `GET /`
  - Controller: `ViewerController::showPad`
  - Query: `file=/path/to/file.pad`
  - Purpose: compatibility entry route that redirects to native Files viewer URL.

- `GET /by-id/{fileId}`
  - Controller: `ViewerController::showPadById`
  - Purpose: compatibility entry route via file ID; redirects to native Files viewer URL.

- `GET /embed/by-id/{fileId}`
  - Controller: `EmbedController::showById`
  - Purpose: minimal authenticated embed page for trusted same-site / trusted-origin integrations.
  - Behavior:
    - requires a logged-in Nextcloud user
    - validates that `fileId` resolves to an accessible `.pad` file in the user's file tree
    - renders a blank embed page that internally calls `open-by-id`
    - injects CSRF token manually into the blank template because this layout does not receive the normal `OC.requestToken` bootstrap
    - if open fails with `Missing YAML frontmatter`, the embed page retries once after `initialize-by-id/{fileId}`
    - sets route-specific `frame-ancestors` from admin-configured trusted embed origins
  - Host message contract:
    - accepted incoming messages from trusted origins:
      - `epnc:host-visible`
      - `epnc:host-hidden`
      - `epnc:host-before-close`
      - `epnc:host-sync-now`
    - emitted replies to the sending host origin:
      - `epnc:sync-flush-started`
      - `epnc:sync-flush-finished`
      - `epnc:sync-flush-failed`
    - intended use:
      - host sends `epnc:host-before-close`
      - waits briefly for `epnc:sync-flush-finished` or `epnc:sync-flush-failed`
      - only then unmounts the iframe

- `GET /embed/create-by-parent/{parentFolderId}`
  - Controller: `EmbedController::createByParent`
  - Query:
    - `name` (required)
    - `accessMode` (`public|protected`, optional, default `protected`)
  - Purpose: minimal authenticated create launcher page for trusted same-site / trusted-origin integrations.
  - Behavior:
    - requires a logged-in Nextcloud user
    - validates that `parentFolderId` resolves to an accessible writable folder in the user's file tree
    - renders a blank page that internally calls `POST /api/v1/pads/create-by-parent` same-origin with CSRF token
    - injects CSRF token manually into the blank template because this layout does not receive the normal `OC.requestToken` bootstrap
    - on success redirects itself to the returned `embed_url`
    - sets route-specific `frame-ancestors` from admin-configured trusted embed origins

- `GET /public/{token}`
  - Controller: `PublicViewerController::showPad`
  - Query (folder share): `file=/subfolder/file.pad`
  - Purpose: compatibility route for public shares; redirects to `/s/{token}` with selected file.
  - UX behavior:
    - Errors are rendered as `noviewer` template (not raw JSON).
    - Error page includes back-link to share entry page (`/s/{token}`).

- `GET /api/v1/public/open/{token}`
  - Controller: `PublicViewerController::openPadData`
  - Query (folder share): `file=/subfolder/file.pad`
  - Purpose: resolves a `.pad` file inside a public share for the native viewer.
  - Result:
    - writable protected share: Etherpad URL plus one `sessionID` `Set-Cookie` header
    - read-only protected share: `is_readonly_snapshot=true`, empty `url`, `snapshot_text`, and sanitized `snapshot_html`; no Etherpad session cookie
    - public/external pad share: regular public Etherpad URL

- `POST /api/v1/pads`
  - Controller: `PadController::create`
  - Params:
    - `file` (required)
    - `accessMode` (`public|protected`, optional, default `protected`)
  - Result: creates pad, file, and binding.

- `POST /api/v1/pads/create-by-parent`
  - Controller: `PadController::createByParent`
  - Params:
    - `parentFolderId` (required, Nextcloud folder/file ID of the writable target folder)
    - `name` (required, filename base; `.pad` suffix is appended if missing)
    - `accessMode` (`public|protected`, optional, default `protected`)
  - Purpose: creates a managed `.pad` file inside an existing parent folder without requiring the client to construct a full path string.
  - Result includes:
    - `file`
    - `file_id`
    - `parent_folder_id`
    - `pad_id`
    - `access_mode`
    - `pad_url`
    - `viewer_url`
    - `embed_url`
  - Intended use:
    - trusted same-origin launcher pages inside Nextcloud
    - not direct server-side cross-app mutation without a real Nextcloud user session

- `POST /api/v1/pads/from-url`
  - Controller: `PadController::createFromUrl`
  - Params:
    - `file` (required)
    - `padUrl` (required, absolute `https` URL with `/p/{padId}`)
  - Purpose: creates `.pad` for public Etherpad links from external servers.
  - External `.pad` files are file-only metadata/snapshot records and do not create rows in `ep_pad_bindings`.
  - Security rules:
    - public pad URLs only
    - no GroupPad IDs (`g.<group>$<name>`)
    - no local/private/reserved target addresses (DNS/IP checks)
    - DNS result is pinned for the outbound fetch (rebinding mitigation)
    - external `/export/txt` responses are size-limited (5 MiB hard limit)
    - external sync accepts only safe text-oriented response content-types

- `POST /api/v1/pads/open`
  - Controller: `PadController::open`
  - Params: `file=/path/file.pad`
  - Result: secure open URL.
  - Behavior: read-only (no auto-mutation of `.pad` metadata), CSRF-protected.
  - Protected mode: response includes one Etherpad session `Set-Cookie` header.

- `POST /api/v1/pads/open-by-id`
  - Controller: `PadController::openById`
  - Params: `fileId=<int>`
  - Result: secure open URL via stable Nextcloud `fileId`.
  - Behavior: read-only (no auto-mutation of `.pad` metadata), CSRF-protected.
  - Protected mode: response includes one Etherpad session `Set-Cookie` header.

- `POST /api/v1/pads/initialize`
  - Controller: `PadController::initialize`
  - Params: `file=/path/file.pad`
  - Purpose: explicit frontmatter initialization for empty/legacy `.pad` files.

- `POST /api/v1/pads/initialize-by-id/{fileId}`
  - Controller: `PadController::initializeById`
  - Purpose: explicit frontmatter initialization by stable Nextcloud `fileId`.

- `GET /api/v1/pads/meta-by-id/{fileId}`
  - Controller: `PadController::metaById`
  - Purpose: read-only metadata endpoint for external UIs that need stable file context without triggering open/session bootstrap.
  - Result includes:
    - `is_pad`
    - `is_pad_mime`
    - `file_id`
    - `name`
    - `path`
    - `access_mode`
    - `is_external`
    - `pad_id`
    - `pad_url`
    - `public_open_url`
    - `viewer_url`
    - `embed_url`

- `GET /api/v1/pads/resolve`
  - Controller: `PadController::resolveById`
  - Query:
    - `fileId=<int>` (preferred)
    - `file=/path/file.pad` (path fallback)
  - Result: MIME/path/viewer target for files frontend.

- `POST /api/v1/pads/sync/{fileId}`
  - Controller: `PadController::syncById`
  - Optional query: `force=1`
  - Result: snapshot sync Etherpad -> `.pad` (`updated` or `unchanged`).
  - `force=1` requests an immediate upstream re-check, but unchanged snapshots are still not rewritten.
  - External pads:
    - Sync uses public text export only (`/export/txt`) based on `pad_url`.
    - HTML is not imported for external pads.
    - No DB binding is required; the external target is validated from `.pad` frontmatter.

- `GET /api/v1/pads/sync-status/{fileId}`
  - Controller: `PadController::syncStatusById`
  - Result:
    - `status=synced` if `snapshot_rev >= current_rev`
    - `status=out_of_sync` if `snapshot_rev < current_rev`
    - `status=unavailable` for external pads without safe revision lookup
    - External pads return `unavailable` because the app intentionally does not keep revision state for remote servers.

- `POST /api/v1/pads/trash`
  - Controller: `PadController::trash`
  - Params: `file=/path/file.pad`
  - Result:
    - `200` with `status=trashed` for successful trash flow.
      - includes `snapshot_persisted` (`true|false`) if file lock prevented snapshot write.
      - includes `delete_pending` (`true|false`): `true` when Etherpad delete is deferred to background job.
    - `409` with `status=skipped` + `reason` on invalid lifecycle state (for example already pending delete).
      - includes transition-race guard reason `binding_state_transition_conflict` on concurrent state updates.

- `POST /api/v1/pads/restore`
  - Controller: `PadController::restore`
  - Params: `file=/path/file.pad`
  - Result:
    - `200` with `status=restored` for successful restore flow.
    - `409` with `status=skipped` + `reason` on invalid lifecycle state.
      - includes transition-race guard reason `binding_state_transition_conflict` on concurrent state updates.

- `POST /api/v1/pads/recover-from-snapshot/{fileId}`
  - Controller: `PadController::recoverByFileId`
  - Purpose: manual recovery entry point for `.pad` files that ended up without a binding row (WebDAV backup restore, `occ files:scan`, direct DB intervention, file copy). Reuses the same "frontmatter → fresh pad" path as `NodeRestoredEvent` but is guarded so it refuses when a binding row already exists.
  - Result:
    - `200` with `status=restored`, `old_pad_id`, `new_pad_id` on success. Always provisions a fresh pad — `pad_id` from frontmatter is never reused.
    - `409` with `status=skipped` + `reason=external_pad` for external (`ext.*`) frontmatter; recovery doesn't apply there.
    - `409` with `message` and the `PadAlreadyHasBindingException` mapping if a binding row already exists for the file.

- `GET /api/v1/pads/find-original/{fileId}`
  - Controller: `PadController::findOriginalByFileId`
  - Purpose: look up whether the orphan's frontmatter `pad_id` is bound to another `.pad` the requester can read. Used by the recovery UI to offer "Open the original" when a copy is detected.
  - Result:
    - `200` with `{ found: true, file_id, path, viewer_url }` when the lookup hits **and** the bound file is readable by the requester.
    - `200` with `{ found: false }` for every miss path (no row, ext.* pad id, trashed/pending-delete binding, binding for a file not addressable in the requester's userspace, unparseable frontmatter, orphan itself not readable, self-loop). Payload shape and status are intentionally identical so the endpoint cannot be used to probe for binding rows that belong to other users.

- `POST /api/v1/admin/settings`
  - Controller: `AdminController::saveSettings`
  - Auth: admin only
  - Stores Etherpad and security settings, including:
    - `etherpad_host` (public/browser base URL)
    - `etherpad_api_host` (optional internal API URL; fallback to `etherpad_host`)
    - `delete_on_trash` (`yes|no`)

- `POST /api/v1/admin/health`
  - Controller: `AdminController::healthCheck`
  - Auth: admin only
  - Result includes:
    - `host`
    - `api_host`
    - `api_version`
    - `pad_count`
    - `latency_ms`
    - `target`
    - `pending_delete_count`

- `POST /api/v1/admin/consistency-check`
  - Controller: `AdminController::consistencyCheck`
  - Auth: admin only
  - Purpose: optional structural integrity check across binding table and `.pad` files.
  - Result includes:
    - `binding_without_file_count`
    - `file_without_binding_count`
    - `invalid_frontmatter_count`
    - `frontmatter_scanned`
    - `frontmatter_skipped`
    - `samples` (bounded debug sample lists per issue class)

- `POST /api/v1/admin/retry-pending-deletes`
  - Controller: `AdminController::retryPendingDeletes`
  - Auth: admin only
  - Purpose: immediate retry of deferred Etherpad deletions:
    - `state=pending_delete`
  - Result:
    - `attempted`
    - `resolved`
    - `failed`
    - `remaining`

- `POST /api/v1/admin/test-fault`
  - Controller: `AdminController::setTestFault`
  - Auth: admin only
  - Availability: only when Nextcloud `debug` mode is enabled
  - Params:
    - `fault` (string, optional; empty clears active fault)
  - Purpose: deterministic E2E fault injection for lifecycle error-path testing.
  - Supported fault values:
    - `trash_read_lock`
    - `trash_write_lock`
    - `trash_write_fail`
    - `restore_read_lock`
    - `restore_write_lock`
    - `restore_write_fail`

## Important Response Fields

- `viewer_url`: URL for viewer redirect.
- `embed_url`: URL for the minimal authenticated embed page (`/embed/by-id/{fileId}`).
- `pad_id`: Etherpad pad ID.
- `pad_url`: preferred target URL for public/external pads.
- `access_mode`: `public` or `protected`.
- `status` (sync): `updated` or `unchanged`.
- `snapshot_rev` (sync): Etherpad revision currently persisted in `.pad`.
- `sync_status_url` (open/open-by-id): endpoint for revision-based sync status in viewer.
- `code` (errors): stable identifier on selected error responses, currently `missing_binding` for `MissingBindingException`. The viewer and embed use this to swap a dead-end error message for the recovery UI (`POST /api/v1/pads/recover-from-snapshot/{fileId}` + optional `GET /api/v1/pads/find-original/{fileId}` lookup).

## Cookie Behavior (Protected Pads)

- Controllers use explicit `Set-Cookie` response headers for Etherpad session bootstrap.
- Rationale: this flow needs explicit cookie attributes for iframe cross-subdomain sessions.
- Current contract:
  - one custom Etherpad `Set-Cookie` header line is written by this app on protected-open responses that open writable Etherpad iframes
  - public read-only protected shares render the stored `.pad` snapshot and do not set an Etherpad session cookie
  - no additional custom app cookies are added in the same response
- If future changes introduce multiple app-level cookies on these responses, this must be implemented and tested explicitly.

## Frontend API Usage

- `src/files-main.js`
  - wires the Files/public-share frontend modules.
- `src/files/open-action.js`
  - extracts `fileId` directly from the authenticated Files action context whenever available.
  - uses `GET /api/v1/pads/resolve` mainly as a fallback to convert file path -> `fileId` when no stable `fileId` is available.
- `src/files/pad-opener.js`
  - opens in files view through Nextcloud router (`fileid`, `openfile=true`).
  - clears `openfile`/`editing` again when the native viewer closes.
- `src/files/public-pad-menu.js`
  - registers `Public pad` in `+ Neu` via API-only runtime capability checks:
    - modern: `addNewFileMenuEntry` / `getNewFileMenu().registerEntry`
    - legacy fallback: `OC.Plugins.register('OCA.Files.NewFileMenu', ...)`
- `src/files/public-share-pad-links.js`
  - global click interception is only used on public-share routes to remap share download links to the pad viewer.
- `src/files/route-controller.js`
  - normalizes stale `.pad` Files routes without `openfile=true`.
  - opens public-share pad links through the native viewer when available.
- `src/viewer-main.js`
  - prefers `POST /api/v1/pads/open-by-id` (`fileId`, requesttoken).
  - falls back to `POST /api/v1/pads/open` (`file`, requesttoken) only without `fileId`.
  - if open fails with missing frontmatter, calls `POST /api/v1/pads/initialize*` and retries open once.
  - if open fails with `code=missing_binding`, renders a recovery card with an optional `GET /api/v1/pads/find-original/{fileId}` lookup and a `POST /api/v1/pads/recover-from-snapshot/{fileId}` action.
  - uses `POST /api/v1/pads/sync/{fileId}` periodically and on unload.
- `src/embed-main.js`
  - powers the minimal `/embed/by-id/{fileId}` page.
  - uses same-origin `POST /api/v1/pads/open-by-id`.
  - if open fails with missing frontmatter, calls `POST /api/v1/pads/initialize-by-id/{fileId}` and retries once.
  - if open fails with `code=missing_binding`, renders the same recovery flow as the inline viewer (lookup + recover).
  - sets the returned `response.url` directly on the internal iframe.
  - uses the returned `sync_url` / `sync_interval_seconds` to trigger the same snapshot sync contract as the native viewer.
  - listens for trusted parent-frame `postMessage` events:
    - `epnc:host-visible`
    - `epnc:host-hidden`
    - `epnc:host-before-close`
    - `epnc:host-sync-now`
- `src/embed-create-main.js`
  - powers the minimal `/embed/create-by-parent/{parentFolderId}` page.
  - uses same-origin `POST /api/v1/pads/create-by-parent`.
  - redirects to returned `embed_url` after successful pad creation.

## URL Control in Files App

- Normal start: `/index.php/apps/files/files`
- `.pad` open target: `/index.php/apps/files/files/{fileId}?dir=...&editing=false&openfile=true`
- Legacy/compat fallback deep-link: `/index.php/apps/etherpad_nextcloud/by-id/{fileId}`
- Stale URL normalization:
  - Route `/apps/files/files/{fileId}?dir=...` without `openfile=true` is normalized (for `.pad`) to `/apps/files/files?dir=...` so future `.pad` opens continue to work correctly.

## Test Scripts

- `tests/integration/e2e-pad-flow.sh`
  - happy path: create -> open -> trash -> restore -> open
- `tests/integration/e2e-sync-failure.sh`
  - failure path: create -> sync(force=1) must fail with non-2xx when Etherpad is down
  - goal: no silent best-effort success on critical sync
- `tests/integration/e2e-lifecycle-state-guards.sh`
  - state guards: restore(active) and trash(pending_delete) must return `409 status=skipped`
- `tests/integration/e2e-lifecycle-trash-failure.sh`
  - deferred-delete path: create -> trash remains `200` with `delete_pending=true` when Etherpad is down
  - post-condition: trash-again must return `409 status=skipped` (`binding_not_active`)
- `tests/integration/e2e-lifecycle-restore-failure.sh`
  - failure path: create -> trash -> restore must fail with non-2xx when Etherpad is down
  - post-condition: trash-again must return `409 status=skipped` (`binding_not_active`)
- `tests/integration/e2e-lifecycle-trash-lock-tolerant.sh`
  - lock path: inject `trash_write_lock`, trash must stay `200` and return `snapshot_persisted=false`
  - post-condition: restore still succeeds after fault is cleared
- `tests/integration/e2e-lifecycle-restore-write-failure.sh`
  - write-failure path: inject `restore_write_fail`, restore must fail non-2xx
  - post-condition: restore succeeds after fault is cleared
- `tests/integration/e2e-public-share-folder.sh`
  - folder share: viewer/open/download/reopen + DAV-style `file` parameter + route switch
- `tests/integration/e2e-public-share-single-file.sh`
  - single-file share: viewer/open/download/reopen + DAV-style `file` parameter + route switch

## Nextcloud Events/Listeners

Registered in `lib/AppInfo/Application.php`.

- `OCA\Files\Event\LoadAdditionalScriptsEvent` -> `LoadFilesScriptsListener`
- `OCA\Viewer\Event\LoadViewer` -> `LoadViewerListener`
- `OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent` -> `LoadPublicShareScriptsListener`
- `OCA\Files_Trashbin\Events\MoveToTrashEvent` -> `MoveToTrashListener`
- `OCA\Files_Trashbin\Events\NodeRestoredEvent` -> `RestoreFromTrashListener`
- `OCP\Security\CSP\AddContentSecurityPolicyEvent` -> `CSPListener`
- `OCP\Files\Template\RegisterTemplateCreatorEvent` -> `RegisterTemplateCreatorListener`

## App Config Keys

- `etherpad_host`
- `etherpad_api_key`
- `etherpad_api_version` (default `1.2.15`)
- `etherpad_cookie_domain`
  - Optional explicit cookie domain for protected pad session bootstrap.
  - Fallback when empty:
    - derived from `etherpad_host`
    - IP/invalid hosts -> empty domain attribute
    - recommendation: set explicitly for complex proxy/subdomain setups
- `delete_on_trash` (`yes|no`, default `yes`)
- `sync_interval_seconds` (default `120`, clamp `5..3600`)
- `allow_external_pads` (`yes|no`, default `yes`)
- `external_pad_allowlist` (newline-separated host list, optional)
- `trusted_embed_origins` (newline-separated absolute `https://origin` list, optional)
  - used for the route-specific `frame-ancestors` policy on:
    - `/embed/by-id/{fileId}`
    - `/embed/create-by-parent/{parentFolderId}`
  - when empty, no external embedding origin is added beyond `'self'`
- `test_fault` (debug-only E2E fault injection; empty by default)
