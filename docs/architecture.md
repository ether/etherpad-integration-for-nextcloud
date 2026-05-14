# Etherpad Nextcloud Plugin Architecture

SPDX-License-Identifier: AGPL-3.0-or-later

## Goal

The `etherpad_nextcloud` app integrates Etherpad for `.pad` files in Nextcloud with a native-viewer-first approach.
Etherpad is the editing source of truth; the `.pad` file acts as binding storage and snapshot container.

## Core Components

- `lib/Service/BindingService.php`
  - Manages the central DB table `ep_pad_bindings`.
  - Owns mapping `file_id <-> pad_id` and states (`active`, `pending_delete`).
  - Only managed internal pads are bound. External pads are represented solely by `.pad` frontmatter and snapshots.
- `lib/Service/LifecycleService.php`
  - Trash/restore flow.
  - Snapshot on trash, re-provisioning on restore.
  - On Etherpad delete failures: `pending_delete` instead of blocking Nextcloud trash.
- `lib/BackgroundJob/*PendingDeleteRetryJob.php`
  - Bucketed retry for deferred Etherpad deletions:
    - hot rows: every 5 minutes for the first hour after trash (`deleted_at <= 1h ago`)
    - warm rows: hourly from 1h to 24h after trash
    - cold rows: daily after 24h
  - The legacy `RetryPendingDeleteJob` class remains as a compatibility shim for existing queued jobs.
- `lib/Service/EtherpadClient.php`
  - Adapter for Etherpad HTTP API (pad create/delete/session/read-only/export).
- `lib/Service/PadFileService.php`
  - Parser/serializer for `.pad` v1 (YAML + snapshot body).
  - Revision metadata and snapshot body structure (`[TEXT]`, `[HTML-BEGIN]`, `[HTML-END]`).
- `lib/Service/PadSessionService.php`
  - Session-cookie flow for protected GroupPads.
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
- `lib/Controller/PadController.php`
  - API for create/open/resolve/sync/trash/restore.
  - For protected pad opens, attaches explicit Etherpad `Set-Cookie` session header via response header.

## Frontend Build

Frontend code is authored as ES modules in `src/` and built with Vite into
checked-in runtime assets in `js/`.

- Build entrypoints are defined in `vite.config.js`:
  - `src/files-main.js`
  - `src/viewer-main.js`
  - `src/embed-main.js`
  - `src/embed-create-main.js`
  - `src/admin-settings.js`
- Shared browser/Nextcloud helpers live in `src/lib/`.
- Files-app specific modules live in `src/files/`.
- Nextcloud loads built assets from `js/` via `Util::addScript(...)`; blank embed templates load their built bundles explicitly.
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
  - `SnapshotExtractor` only reads stored snapshot text + sanitized HTML for viewers.

## Main Flows

### 1) Create

1. `PadController::create` creates an Etherpad pad (public or protected/group).
2. Creates the `.pad` file.
3. Writes initial frontmatter.
4. Creates DB binding.
5. External create-from-URL is different: it only creates the `.pad` file with external frontmatter plus an optional text snapshot; it does not create or own anything on the remote Etherpad server.

### 2) Open (authenticated)

Primary flow (native viewer):

1. `src/files-main.js` opens `.pad` in Nextcloud Files viewer route (`/apps/files/files/{fileId}?openfile=true`).
   - on authenticated files routes, it now extracts the stable `fileId` directly from the Nextcloud file-action context whenever available
   - path-based resolve is only a fallback for contexts without a usable `fileId`
2. `src/viewer-main.js` resolves Etherpad open data via API:
   - preferred: `POST /api/v1/pads/open-by-id` (`fileId`, CSRF `requesttoken`)
   - fallback: `POST /api/v1/pads/open` (`file`, CSRF `requesttoken`) if no stable `fileId` is available
3. `PadController` validates frontmatter/binding and resolves secure open URL:
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
5. On `Missing YAML frontmatter`, the embed page retries once after `POST /api/v1/pads/initialize-by-id/{fileId}`.
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
5. `PadController::createByParent` performs server-side validation of `name`, `accessMode`, and the writable target folder before creating the `.pad` file and binding.
6. On success the launcher redirects itself to the returned `embed_url`, after which the normal embed-open flow takes over.

### 3) Open (public share)

Primary flow (native viewer when available):

1. Public share routes stay on Nextcloud share URL (`/s/{token}`).
2. `src/viewer-main.js` detects public share context and resolves open data via:
   - `GET /api/v1/public/open/{token}?file=...`
3. Same open-target rules apply:
   - read-only share: Etherpad read-only URL
   - editable share: regular URL/session
4. For protected share-open flows, session bootstrap uses one explicit `Set-Cookie` header.
5. Compatibility route `/apps/etherpad_nextcloud/public/{token}` redirects to native share route `/s/{token}`.

## Cookie Header Model

- Cookie construction is centralized in `PadSessionService` (`buildSetCookieHeader()`).
- We intentionally use explicit attributes required for Etherpad iframe sessions across subdomains:
  - `Domain`
  - `Secure`
  - `SameSite=None`
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
3. `PadController::syncById` fetches revision state from Etherpad.
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

- Trash: persist a fresh snapshot if possible, delete the managed Etherpad pad, then delete the binding row.
- If Etherpad is unavailable during delete: switch state to `pending_delete`, keep Nextcloud trash successful.
- Restore: provision a new pad from `.pad` frontmatter/snapshot when no binding row exists, or finish a `pending_delete` row if one remains.
- External pads skip lifecycle side effects entirely. Trash/restore only affects the Nextcloud file; the remote Etherpad server is never mutated.
- Pending-delete cleanup is retried in age buckets: first every 5 minutes, then hourly, then daily.

### 6) Admin Integrity Check (optional)

1. Admin runs `POST /api/v1/admin/consistency-check`.
2. Service scans DB/file metadata consistency.
3. Returns aggregate counters and bounded sample lists for diagnostics.
4. External `.pad` files without bindings are expected and are excluded from missing-binding diagnostics.

## Main Frontend Modules

- `src/files-main.js`
  - Thin files-app entrypoint that wires the modules below.
- `src/files/open-action.js`
  - Registers the authenticated `.pad` default file action.
  - Uses stable `fileId` from the Files action context whenever available.
- `src/files/pad-opener.js`
  - Opens authenticated `.pad` files through Nextcloud router with `fileid` + `openfile=true`.
  - Target route: `/index.php/apps/files/files/{fileId}?dir=...&editing=false&openfile=true`.
  - Falls back to hard navigation when the native viewer/router path cannot be used.
- `src/files/created-pad-opener.js`
  - Handles direct viewer open after creating a new public pad.
  - Emits the Nextcloud Files creation event, waits briefly for SPA state registration, then calls native Viewer open.
- `src/files/route-controller.js`
  - Watches Files/public-share route changes.
  - Normalizes stale `.pad` routes without `openfile=true` back to folder routes.
  - Opens public-share pad links through the native viewer when available.
- `src/files/public-pad-menu.js`
  - `+ Neu` integration for `Public pad` with runtime capability checks:
    - modern API: `addNewFileMenuEntry` / `getNewFileMenu().registerEntry`
    - legacy API fallback: `OC.Plugins.register('OCA.Files.NewFileMenu', ...)`
- `src/files/public-share-pad-links.js`
  - Public-share click interception for download links that need remapping to the pad viewer.
  - Authenticated Files routes intentionally do not use global click interception.
- `src/files/public-single-share-ui.js`
  - Public single-file share UI state refresh.
- `src/files/public-pad-create-flow.js` and `src/files/pad-create-dialogs.js`
  - Public pad creation flow and modal UI.
- `src/viewer-main.js`
  - Registers Nextcloud viewer handler for MIME `application/x-etherpad-nextcloud`.
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
  - Load scripts for files app.
- `OCA\Viewer\Event\LoadViewer`
  - Load viewer handler.
- `OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent`
  - Load scripts on public-share pages.
- `OCA\Files_Trashbin\Events\MoveToTrashEvent`
  - Trash lifecycle.
- `OCA\Files_Trashbin\Events\NodeRestoredEvent`
  - Restore lifecycle.
