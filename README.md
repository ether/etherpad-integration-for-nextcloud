# Etherpad Integration for Nextcloud

This plugin lets you surface pads from an Etherpad instance inside Nextcloud and organize them there like other files.

## Core Features

- Each Etherpad pad is represented by a `.pad` file inside Nextcloud
- `.pad` files live in normal Nextcloud folders and integrate with sharing, trash, restore, and file organization
- Opening a `.pad` file in Nextcloud opens the linked Etherpad pad inside the native Nextcloud file viewer in an iframe
- Protected and public pad modes
- Public folder/file share support for `.pad`
- Periodic sync from Etherpad into `.pad` snapshots
- Trash deletes on Etherpad (with deferred retry when Etherpad is temporarily unavailable)
- Restore recreates pads from `.pad` snapshot data

## Requirements

- Nextcloud `30` to `33` (see [appinfo/info.xml](appinfo/info.xml))
- Etherpad reachable from Nextcloud server
- Etherpad API key
- HTTPS for production deployments
- For protected pads in the embedded viewer: Nextcloud and Etherpad must allow iframe embedding and send compatible cookies.
- Recommended: run Nextcloud and Etherpad on the same registrable domain, for example `cloud.example.org` + `pad.example.org`.

## Etherpad Compatibility

- Works with different Etherpad releases via API version detection (fallback supported).
- This plugin requires Etherpad API key mode (`authenticationMethod: "apikey"`).
- OAuth-only Etherpad setups are not supported by this plugin.

## Ownpad Compatibility

- Running this app and Ownpad at the same time is not supported.
  - Both apps hook into `.pad` MIME/viewer handling, which leads to ambiguous file-type resolution and open-action conflicts.
- This app does currently not automatically import Ownpad legacy `.pad` files.
- Ownpad `.pad` files (`[InternetShortcut]` + `URL=...`) are currently rejected by default because of a different binding/lifecycle model than Ownpad (including database state handling), so a safe migration is non-trivial.
- A dedicated, explicit migration/import flow for legacy Ownpad `.pad` files is planned for the future. Feel free to commit your solutions for that!

## Install

The repository contains Vite-built frontend assets in `js/`. If you change
files in `src/`, rebuild before copying the app:

```bash
npm install
npm run build
```

### 1) Copy app into Nextcloud

Place this repository as:

`<nextcloud-root>/apps/etherpad_nextcloud`

### 2) Enable app

```bash
php occ app:enable etherpad_nextcloud
```

### 3) (If needed) rebuild mimetype caches

Run this if `.pad` icons/actions do not appear correctly after install/upgrade:

```bash
php occ maintenance:mimetype:update-js
php occ maintenance:mimetype:update-db
```

### 4) Open admin settings

Go to:

`Settings -> Administration -> Pads`

and configure:

- Etherpad Base URL
- Etherpad API URL (optional; defaults to Base URL)
- Etherpad API key (OAuth is not required; Etherpad API key auth is used)
- Copy content to `.pad` file interval
- Delete-on-trash policy
- External public pad policy

### 5) Check iframe and cookie setup for protected pads

If protected pads should open inside the Nextcloud viewer iframe:

- Etherpad responses must allow embedding from your Nextcloud origin.
- Reverse proxies must not enforce a conflicting `X-Frame-Options` policy.
- A `Content-Security-Policy: frame-ancestors ...` header on the Etherpad side is the most reliable modern setup.
- If Nextcloud and Etherpad are on the same registrable domain, Etherpad's default `SameSite: Lax` session cookie usually works.
- If they are on different registrable domains, set Etherpad `cookie.sameSite` to `"None"` and keep HTTPS + `trustProxy: true`.

Example:

- `cloud.example.org` + `pad.example.org` -> usually works with default `SameSite: "Lax"`
- `cloud.example.org` + `pad.otherdomain.example` -> usually requires `SameSite: "None"` and HTTPS

## Upgrade

1. Replace app files in `apps/etherpad_nextcloud`
2. Run:

```bash
php occ app:disable etherpad_nextcloud
php occ app:enable etherpad_nextcloud
php occ maintenance:mimetype:update-js
php occ maintenance:mimetype:update-db
```

For deployment, copy the app to `apps/etherpad_nextcloud` and exclude development-only content such as `.git/`, `node_modules/`, `tests/`, `docs/`, `.phpunit.cache/`, and local temp files. Keep the built `js/` assets in the deployed app.

## Development Checks

Frontend source lives in `src/` and is built into `js/` with Vite.

```bash
npm test
npm run build
```

PHP checks and optional E2E checks are described in [docs/release-process.md](docs/release-process.md).

## Usage

### Create pads

- `+ New -> New pad`
- `+ New -> Public pad` (internal public pad on the configured Etherpad instance, or external public pad by URL)

### Open pads

- Click `.pad` file in Files app
- App uses Nextcloud native viewer flow (`openfile=true`)

### Sync

- One-way sync only: content is copied from Etherpad into the `.pad` file snapshot.
- No automatic reverse sync from `.pad` file content back into Etherpad.
- Automatic while viewer is open (interval from admin settings) and on viewer hide / page unload.
- Backend endpoint `POST /api/v1/pads/sync/{fileId}` remains available for programmatic syncs.

### Trash/Restore

- When a `.pad` file is moved to the Nextcloud trash, the linked Etherpad pad is deleted.
- If Etherpad is temporarily unavailable, delete is deferred and retried.
- When the `.pad` file is restored from the Nextcloud trash, a new pad is recreated and the snapshot from the `.pad` file is replayed.

## Troubleshooting

### `.pad` downloads instead of opening in viewer

- Ensure app is enabled:
  - `php occ app:list | grep etherpad_nextcloud`
- Rebuild mimetype caches:
  - `php occ maintenance:mimetype:update-js`
  - `php occ maintenance:mimetype:update-db`
- Reload browser with hard refresh

### Wrong `.pad` icon (fallback/red icon)

- Re-run mimetype update commands above
- Check that app CSS loads
- Confirm app migration alias is applied (app re-enable usually handles this)

### `+ New` entries missing

- Hard refresh browser once
- Confirm Files app JS loaded without fatal errors in browser console

### Protected pad permission errors

- Verify Etherpad API key and Etherpad auth mode
- Run admin `Health check` in `Settings -> Administration -> Pads`

### Protected pads fail because of cookies / iframe auth

- Check Etherpad cookie settings:
  - same registrable domain: default `SameSite: "Lax"` is usually enough
  - different registrable domains: use `cookie.sameSite: "None"` and `trustProxy: true`
- HTTPS is required when using `SameSite=None`
- Some hosting domains that look related are still treated as cross-site by browsers

### Etherpad is blocked inside the Nextcloud viewer iframe

- Check response headers on the Etherpad side and in the reverse proxy
- Remove or relax conflicting `X-Frame-Options` rules
- Prefer a `Content-Security-Policy: frame-ancestors 'self' https://your-nextcloud.example` header that explicitly allows your Nextcloud origin

### iPhone / iOS Safari zooms when focusing the embedded editor

- Usually caused by small editor/form font sizes inside Etherpad, not by the outer Nextcloud shell
- For the default `colibris` skin, adjust `src/static/skins/colibris/pad.css`, for example in the mobile `@media (max-width: 768px)` section, and raise the effective pad/editor font size from `15px` to `16px`
- Test in a private Safari tab or after clearing website data because Etherpad CSS is cached aggressively

## Documentation

- Architecture: [docs/architecture.md](docs/architecture.md)
- API routes: [docs/api-reference.md](docs/api-reference.md)
- Etherpad integration details: [docs/etherpad-integration.md](docs/etherpad-integration.md)
- `.pad` format: [docs/pad-format.md](docs/pad-format.md)
- I18N: [docs/i18n.md](docs/i18n.md)
- UI icons: [docs/ui-icons.md](docs/ui-icons.md)
- Testing and release checks: [docs/release-process.md](docs/release-process.md)

## License

- App code: AGPL-3.0-or-later (full text: [LICENSES/AGPL-3.0.txt](LICENSES/AGPL-3.0.txt))
- Etherpad logo assets in `img/etherpad-icon-*.svg`: Apache-2.0 (see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md))

## Acknowledgements

- Thanks to the Ownpad project for the groundwork, ideas, and lessons learned that inspired and shaped this plugin.
- Thanks to the Nextcloud and Etherpad communities for the underlying platforms and documentation.
- This project is not affiliated with, endorsed by, or operated by the Ownpad, Nextcloud, or Etherpad projects.
