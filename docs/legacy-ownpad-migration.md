# Legacy Ownpad migration

When a `.pad` file with no YAML frontmatter and an `[InternetShortcut]` block (the legacy Ownpad / Etherpad-Lite format) is opened, the plugin automatically converts it to the current binding-and-`.pad` model on the first open. Users coming from an older Ownpad installation see no modal and no prompts — the pad simply opens.

The detection lives in `PadFileService::parseLegacyOwnpadShortcut`; the migration is driven by `PadLegacyMigrationService`.

## What happens on first open

The migration service compares the shortcut URL's origin (scheme + host + port, normalized) against the configured `etherpad_host` and branches accordingly:

### Different origin — external public pad

The source URL points at an Etherpad server that isn't the one this Nextcloud instance manages. We can't apply protected-mode auth to a server we don't control, so the file is converted to an `ext.*` public pad via the same path as `POST /api/v1/pads/from-url`:

- Frontmatter is written with `pad_id = ext.<remote-id>` and `pad_origin` / `remote_pad_id` extras.
- A best-effort snapshot of the remote pad's text is embedded.
- **No binding row** is created — `ext.*` pads are intentionally not bound, since the plugin doesn't own their lifecycle.

### Same origin — re-bind as managed pad

The source URL points at the configured Etherpad server. The access mode is derived from the source pad-id format:

- `g.<groupId>$<padName>` → protected.
- Anything else → public.

This is a pure local re-bind. No remote pad is created, no content is copied; the existing Etherpad pad keeps running unchanged under our schema:

- New YAML frontmatter is written referencing the same `pad_id`.
- A binding row is inserted.

### Same origin — claim-collision rule

If the pad-id is already bound to another Nextcloud file, the unique constraint on `oc_etherpad_bindings.pad_id` prevents a second binding row. The migration handles the conflict explicitly:

- **Requesting user has read access to the file that owns the existing binding** → managed frontmatter is written into the legacy file, but **no new binding row is created**. The file is now a "copy of a pad" in the plugin's model, and the existing copy-of-a-pad flow handles future opens (offers "Open the original" via `recoverByFileId`).
- **Requesting user has no access to the bound file** → the migration is refused with `LegacyPadCollisionException`, surfaced as HTTP 409 with `code: "legacy_collision_no_access"`. The `.pad` file is left untouched, so the migration offer is still pending on the next open.

This prevents the legacy format from being a backdoor: a malicious user can't drop a hand-crafted `[InternetShortcut]` file pointing at another user's pad and "claim" the binding.

### Same origin — protected import switch

The pad-id in a legacy `.pad` is written by whoever wrote the file, and for a group pad it also names the Etherpad group a later session is minted for — a session grants what the group holds, not only the pad the file named. Where this Nextcloud is the only writer on its Etherpad server, every group pad already has a binding and the collision rule above covers it. Where it is not, a foreign group pad has no binding here, and nothing distinguishes it from the pads this migration exists for: a genuine Ownpad pad lives in a group Ownpad created, never in one of ours, so provenance is exactly what cannot be checked.

`allow_legacy_protected_import` (default `yes`) therefore refuses the import outright. It applies only where no binding exists yet — a pad already bound here was bound by this instance, and which file may claim it stays the collision rule's question. Public pads are unaffected: a pad anyone holding its id can read is no more reachable for having a file bound to it.

Refused imports surface as HTTP 403 with `code: "legacy_protected_import_disabled"`. The `.pad` file is left untouched, so switching the setting back on migrates it on the next open.

## Audit log

Every branch emits a single log line at `info` level (or `warning` for the refusal). Fields:

| Field | Meaning |
|---|---|
| `fileId` | NC file ID being migrated |
| `sourceUrl` | URL parsed from the legacy shortcut |
| `originBranch` | `same` or `cross` |
| `accessMode` | `public` / `protected` / unset for cross-origin |
| `padId` | Source pad-id (same as new pad-id in same-origin re-bind) |
| `collision` | `none` / `with_access` / `no_access` / `self` |
| `uid` | Migrating user |

Grep `app:etherpad_nextcloud` + `legacy Ownpad` in `nextcloud.log` to reconstruct what was imported when. `Migrated legacy Ownpad` alone finds the successes only - both refusals are worded `Refused legacy Ownpad migration`.

## Out of scope

- **Admin bulk-migration CLI / `occ` command.** Each file migrates on its own first-open, so no coordination is needed while `allow_legacy_protected_import` is on. An instance that switches it off has no way to take over existing protected Ownpad files, which is what such a command would be for.
- **Public → protected conversion of an already-managed pad.** Etherpad's pad-id format encodes the type and can't be renamed in place; converting would require content copy + a new pad. If needed, that lives in a separate "Convert pad access mode" feature.
- **Real-time mirroring** between legacy and current pads.

## Status returned by `/api/v1/pads/initialize*`

When the migration ran, the endpoint returns `status: "migrated_from_legacy"` (instead of the regular `initialized`). The frontend logs this to the browser console on first open; a user-facing toast is not currently surfaced (the codebase has no notification framework wired in).
