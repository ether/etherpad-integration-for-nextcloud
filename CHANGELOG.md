# Changelog

## 1.1.0-alpha.4 — 2026-08-31

Fourth public-review release. Focus: one pad-creation entry point with shared templates, a clearer admin surface, protected-pad session and lifecycle correctness, and a disposable end-to-end stack that runs the browser suite across three Nextcloud and two Etherpad majors.

### Added

- **Instance-wide pad templates.** Administrators upload and manage shared `.pad` templates; they appear in Nextcloud's template picker for everyone and always create a fresh Etherpad pad. (#181)
- **One "New pad" entry.** The separate protected/public/external entries in the New menu are replaced by a single entry, with the pad type and any shared template chosen in Nextcloud's own template picker. (#187)
- **Configurable pad types.** Protected and public pads can be enabled or disabled independently; pads of a disabled type keep working, and external pads keep their own setting. (#177)
- **Italian locale**, alongside German, Spanish and French. (#188)

### Changed

- **Admin page grouped into sections**, with the descriptions rewritten. (#178)
- **Connection test reports each part separately** — API, API key, browser-facing Etherpad URL, protected-pad cookie — with field-specific guidance where a setting needs attention. (#179)
- The cookie-domain calculation used by the settings page, the diagnostics and the runtime is one implementation. (#179)

### Security

- **The Etherpad session cookie is `SameSite=Lax`.** It was `None`, so any page on the web could frame a pad URL and a visiting user's cookie went with it. `etherpad_session_cookie_samesite=none` remains for a cross-site embed behind cookie-independent authentication. (#210)
- **`HttpOnly` from Etherpad 3.0.** The release is detected from `/health`; up to 2.7.3 the pad app reads the cookie in the browser, where `HttpOnly` would lock users out of every protected pad. (#209)
- **Deleting a protected pad removes its Etherpad group and that group's sessions**, instead of leaving both behind with nothing pointing at them. (#208)
- **Legacy Ownpad migration checks that a group pad is in the group it names**, closing a path where a hand-written `.pad` could name someone else's Etherpad group and have a session minted for it. (#208)

### Fixed

- **Several protected pads stay open at once.** Opening a second one no longer replaces the first one's Etherpad session. (#206)
- **Opening by file id is trustworthy** for group folders, shares, external storage and files whose path changed. (#205)
- **Pad names keep their special characters**, and Nextcloud judges what a valid name is. (#200)
- **Pad files are created atomically**, and a failed create rolls back the file it made. (#198)
- Creating a pad directly in the user's root folder works. (#196)
- Restoring a `.pad` from the trash works on Nextcloud 31. (#190)
- Cleanup and rollback are consistent when creation, binding, restoration or deletion only partly succeeds. (#198, #205, #208)

### Tooling / tests / CI

- **Disposable Docker e2e stack** — Nextcloud, Etherpad, PostgreSQL and TLS — so the browser suite runs against a throwaway target. (#195)
- **The Playwright suite runs in CI** against Nextcloud 31, 32 and 33 on Etherpad 2, and Nextcloud 33 on Etherpad 3. (#195, #207)
- Browser and unit coverage extended across creation, templates, shares, protected sessions, trash and restore, lifecycle cleanup and the security-sensitive paths. (#53, #138, #194)
- Test runs no longer leave fixtures in the Nextcloud trash. (#194)
- DOMPurify updated and the shipped frontend bundles rebuilt with it. (#144, #166, #189)
- Playwright artefacts excluded from the release tarball. (#136)
- Dependabot PRs auto-merge once CI passes. (#151)

## 1.1.0-alpha.3 — 2026-06-09

Third public-review release. Focus: security hardening of the Etherpad/admin surface, a browser end-to-end test suite, and a deep static-analysis / tech-debt pass. No user-facing feature changes since alpha.2.

### Security

- **API key stored as sensitive app config.** The Etherpad API key is now persisted via the sensitive-value app-config path so it is masked in `occ config` output and admin diagnostics instead of being readable in clear text. (#105)
- **External-pad framing requires an explicit allowlist.** The CSP `frame-src` for external Etherpad hosts is no longer opened implicitly; embedding an external pad now requires the host to be on the trusted-origin allowlist. (#102)
- **Client-side snapshot sanitisation.** Snapshot HTML is sanitised with DOMPurify in the browser before rendering, closing a stored-HTML surface in the viewer/recovery path. (#110)

### Changed

- **Etherpad HTTP via `IClientService`.** Outbound Etherpad API calls go through Nextcloud's HTTP client instead of raw transport, picking up proxy/TLS configuration and consistent timeouts. (#103)
- **Shared pad-sync frontend module.** The viewer and embed entry points now share one extracted pad-sync module instead of duplicating the loop. (#106)
- **No per-request MIME registration.** Dropped the MIME-type registration from the `Application` constructor (it ran on every request); the `.pad` MIME type is owned solely by the `RegisterMimeType` repair step. (#108)
- **Legacy retry job retired.** Removed the compatibility `RetryPendingDeleteJob` shim; the tiered Hot/Warm/Cold `TimedJob`s are the sole retry path for pending pad deletes. (#111)
- Removed a batch of dead code surfaced during the refactors. (#104)

### Tooling / tests / CI

- **Playwright end-to-end suite.** 23 browser tests against a live Nextcloud + Etherpad covering create/open, templates + placeholders, trash/restore, move/rename, orphan recovery, ownership boundary, snapshot round-trip, user-to-user share, public-share view, and the admin health check. (#54)
- **Psalm static analysis** enabled in CI with a baseline (#82), then the baseline was burned down: noise reduction via config + stubs + redundant-cast removal (#122/#133), all real type issues fixed so the type baseline is empty, and `findUnusedCode` turned on with `@psalm-api`-annotated entry points (#122/#134).
- CI now fails the build when committed `js/` assets are stale. (#101)
- Version metadata aligned across `appinfo/info.xml`, `package.json`, and `package-lock.json`, guarded by a version-consistency CI check. (#107, #119)

## 1.1.0-alpha.2 — 2026-05-27

Second public-review release. Focus: localisation cleanup, embed-create host signalling, and CI / release infrastructure.

### Added

- **Embed-create result events.** The `/embed/create-by-parent` flow now emits `epnc:create-succeeded` / `epnc:create-failed` postMessages to the parent host so embedders can react to the create outcome without scraping the iframe. (#95, #96)
- **GitHub Actions CI** (`lint-info-xml`, PHPUnit on PHP 8.2/8.3/8.4, npm-build + vitest, info.xml schema check), Dependabot config for npm, composer, and actions. (#75, #83)
- **Release tarball builder** `scripts/build-release-tarball.sh` for reproducible app-store-style builds. (#74)
- **NC-discoverable app icon** at `img/etherpad_nextcloud.svg` (+ dark variant + 512 px PNG) so the Apps page in NC settings shows the Etherpad icon instead of the generic placeholder. (#84)

### Changed

- **Locale cleanup for 1.1.0.** All maintained locales (`de`, `es`, `fr`) brought to 132/132 keys with no orphans. Source strings consolidated (`Health check` → `Test Etherpad connection`, `Pad file` → `.pad file`, unified `Could not …` / `Unable to …` pairs, `External Etherpad host allowlist`, …). DE locale reviewed end-to-end by a native speaker (`Du`-form, full terminology pass). ES and FR are best-effort first-pass translations done with AI assistance + school-level grammar — usable but expected to receive native-speaker polish via translatewiki once the project is onboarded (#98). Dropped the `de_DE` mirror and the legacy `*.php` catalogs — only `*.json` + `*.js` per locale, alphabetically sorted for clean cross-locale diffs. `docs/i18n.md` rewritten for the new layout. (#77)
- **`appinfo/info.xml` schema** now validates against the official apps.nextcloud.com schema; John McLear added as second author for the Etherpad upstream. (#94)
- Dev dependencies refreshed via Dependabot (actions/setup-node 6, actions/checkout 6, actions/cache 5, shivammathur/setup-php 2.37, vitest 4.1.7, dorny/paths-filter 4, skjnldsv/read-package-engines-version-actions 3).

### Investigated (no code change)

- `fileId=-1` preview 400 on fresh-pad create (#99) — reproduced with plain `+ → New Markdown file`; root cause is hard-coded in NC core's `PreviewController` and not actionable plugin-side. Closed as upstream.
- Tiptap unmount warning on fresh-pad create (#72) — re-diagnosed as NC Text-app `RichWorkspace` mounting on page load (fires regardless of pad create). Not in our flow. Closed as wrong diagnosis.

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
