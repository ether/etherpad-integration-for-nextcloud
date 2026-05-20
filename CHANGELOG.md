# Changelog

## 1.1.0-alpha.1 — 2026-05-20

First public-review release. Versioning reset to a clean minor cut with a pre-release marker; not intended for production deployments yet.

### Added

- **Pad templates.** Users can place `.pad` files in their `/Templates` folder and pick them from the `+ → New pad` menu. Mustache-style placeholder substitution for `{{date}}`, `{{user}}`, etc., with same-server access-mode inheritance and a claim-collision guard. See `docs/templates.md`.
- **Legacy Ownpad migration.** `.pad` files in the old `[InternetShortcut]` format are auto-converted on first open. Branching depends on the source URL's origin (same- vs. cross-server) and the pad-id format (`g.X$Y` → protected, anything else → public); claim-collision rule prevents one user's legacy file from claiming another user's bound pad. See `docs/legacy-ownpad-migration.md`.
- **Trusted embed integration** for same-site / trusted-origin hosts:
  - minimal authenticated embed page via `/embed/by-id/{fileId}`
  - trusted `frame-ancestors` / embed-origin allowlist
  - same-origin open flow with CSRF bootstrap inside blank template
- **Trusted embedded create flow** via `/embed/create-by-parent/{parentFolderId}` with redirect into embed viewer.
- **External integration APIs**: `POST /api/v1/pads/create-by-parent`, `POST /api/v1/pads/from-template`, `POST /api/v1/pads/from-url`, `GET /api/v1/pads/meta-by-id/{fileId}`.
- **Preview provider** for `application/x-etherpad-nextcloud` returning the pad-icon as a fallback thumbnail, so the Files app and template picker don't trigger `/core/preview` 4xx responses.

### Changed

- **Architecture cleanup.** `PadController` (347 LOC, 14 actions, 8 service deps) split into `PadCreateController` / `PadSessionController` / `PadLifecycleController` over a shared `AbstractPadController` base — public URL paths unchanged. `ExternalPadExportFetcher` extracted from `EtherpadClient` so the SSRF-hardened external-fetch surface is no longer dragged into services that only need the admin API. `PadLifecycleOperationService` and `PadPathService` folded back into their hosts. Repeated frontmatter-read incantation consolidated into a single `PadFileService::readPad` returning a typed `ParsedPadFile`.
- **Embedded sync UX**: host message hooks for visible/hidden/before-close/sync-now; close-flush ack protocol (`epnc:sync-flush-started|finished|failed`); short lock retries for `.pad` snapshot writes before returning `status=locked`.
- **Protected pad open** is meaningfully faster: earlier iframe start in embed flow, Etherpad author caching per Nextcloud user, author-name sync only on actual display-name changes.

### Fixed

- Fresh `+ → New pad` no longer logs two 4xx network errors on the first `/open` call. The template-flow listener now initialises frontmatter immediately when the user picks the `Blank` option, so the very first open returns 200. Two transient artefacts caused by NC's Files-app placeholder rendering (a `fileId=-1` preview 400 and a Tiptap unmount warning) are out-of-scope for this fix and tracked in #72.

## 1.0.0 - 2026-03-11

- First stable release of **Etherpad Integration for Nextcloud**.
- Native Nextcloud viewer integration for `.pad` files (authenticated and public-share flows).
- Protected/public pad modes with secure session handling for protected pads.
- Admin settings for Etherpad API connection, health check, external public pad policy, and sync interval.
- One-way content sync from Etherpad into `.pad` snapshots (automatic while open + manual trigger).
- Binding-based lifecycle: delete on Nextcloud trash, restore from Nextcloud trash, deferred retries if Etherpad is temporarily unavailable.
- External public pad linking with HTTPS enforcement and SSRF protection.
- NC30–NC33 compatibility with PHPUnit + E2E release checks.
