# Legacy Ownpad migration

When a `.pad` file with no YAML frontmatter and an `[InternetShortcut]` block (the legacy Ownpad / Etherpad-Lite format) is opened, the plugin automatically converts it to the current binding-and-`.pad` model on the first open. Users coming from an older Ownpad installation see no modal and no prompts — the pad simply opens.

The detection lives in `PadFileService::parseLegacyOwnpadShortcut`; the migration is driven by `PadLegacyMigrationService`.

## What happens on first open

The migration service compares the shortcut URL's origin (scheme + host + port, normalized) against the configured `etherpad_host` and branches accordingly. On every branch the document goes in last, after the row lookups, a group's listing or the remote export, and only into the file as the open read it: the migration finds the file again by its id and leaves one that no longer holds the shortcut, with `The .pad file changed before its document was written.` A row the migration made stays for the next open to finish.

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

Before anything is looked up or written - for a new binding row, a migration to finish and a copy alike, and before the switch below - the pad id has to be one Etherpad's API acts on as it is (`PadId::isValid()`), and one a `.pad` file's frontmatter can hold, without NUL. Before every call, Etherpad's API rewrites an id no pad exists under - runs of whitespace and of `:` become `_` (`sanitizePadId()`) - and acts on that other pad: a file naming `victim pad` would be bound under that id, past the collision rule, and open and sync `victim_pad`, someone else's. An id that then fails Etherpad's rule, the API refuses (`getPadSafe()`) - the rule as Etherpad has it from 3.3.3: an optional group of `g.` and sixteen letters or digits with `$`, a name of 1 to 50 UTF-16 code units without `$` or `:`, not `.` or `..`. The app could neither read, sync nor delete such a pad, and after an upgrade to 3.3.3 could not reach one an Etherpad before it still took. So the app refuses `:`, whitespace and the rest of that rule whatever Etherpad runs. A row an earlier version left for such an id stays as it is: its file opens and syncs the pad the API acts on in its place - `victim_pad`, on any Etherpad - until it is deleted for good; then the sweep removes the row and leaves the pad, with a warning ([architecture.md](architecture.md), "Files gone for good").

Etherpad's `lowerCasePadIds` setting has the API lower the capitals of an id no pad exists under, too: with it on, a file naming `NC-ABC` would reach the pad of `nc-abc`'s file. So where no active row names a public pad, no row in any state may name it under other capitals - the column lowered as the database lowers it (ASCII only on SQLite, and on PostgreSQL under the C locale), the id as PHP's `mb_strtolower()` does, a final sigma as σ on both sides, as JavaScript lowers Σ to ς at a word's end, where a database lowering letter by letter makes σ of it, and so does PHP before 8.3. Beyond that, how far it matches depends on how the database lowers letters, by its locale, and on PHP's mbstring and Etherpad's Node - a row with non-ASCII capitals, which a migration makes today too, is missed on SQLite. Nor is the lookup atomic with the insert: two imports of case variants at the same moment can both bind. It is a partial guard, and does not make `lowerCasePadIds` safe. It holds with the setting off too, where `MyPad` and `mypad` are two pads: the second to come is refused. A row an earlier version left under other capitals stays as it is: with the setting on, its file opens and syncs the other file's pad, and the sweep, deleting its pad, takes that one down. The setting is best left off.

Where no row names the pad yet, a group pad has to be listed in the group it names (`listPads`), after the switch below; a group Etherpad does not know is answered as a missing pad, so the answer does not tell whether the group is there. Each of these refusals answers HTTP 400 ("This legacy Ownpad file names a pad that does not exist in Etherpad."), leaves the `.pad` file untouched, and logs `Refused legacy Ownpad migration - the pad it names cannot be linked.` with `reason` `invalid_id`, `case_variant` or `not_in_group`.

### Same origin — claim-collision rule

If the pad-id is already bound to another Nextcloud file, the unique constraint on `ep_pad_bindings.pad_id` prevents a second binding row. The migration handles the conflict explicitly:

- **Requesting user has read access to the file that owns the existing binding** → managed frontmatter is written into the legacy file, but **no new binding row is created**. The file is now a "copy of a pad" in the plugin's model, and the existing copy-of-a-pad flow handles future opens (offers "Open the original" via `recoverByFileId`).
- **Requesting user has no access to the bound file** → the migration is refused with `LegacyPadCollisionException`, surfaced as HTTP 409 with `code: "legacy_collision_no_access"`. The `.pad` file is left untouched, so the migration offer is still pending on the next open.

This prevents the legacy format from being a backdoor: a malicious user can't drop a hand-crafted `[InternetShortcut]` file pointing at another user's pad and "claim" the binding.

### Same origin — protected import switch

The pad-id in a legacy `.pad` is written by whoever wrote the file, and for a group pad it also names the Etherpad group a later session is minted for — a session grants what the group holds, not only the pad the file named. Where this Nextcloud is the only writer on its Etherpad server, a group pad it made carries a binding and the collision rule above covers it — barring one orphaned by a failed cleanup, which the admin settings' consistency check surfaces. Where it is not, a foreign group pad has no binding here, and nothing distinguishes it from the pads this migration exists for: a genuine Ownpad pad lives in a group Ownpad created, never in one of ours, so provenance is exactly what cannot be checked.

`allow_legacy_protected_import` (default `no`) therefore refuses the import outright. It is meant to be turned on only where no other software creates group pads on the same Etherpad server, not as a window during a migration — the forgery is available to any user for as long as it is on. It applies only where no binding exists yet; existing bindings stay with the collision rule above, which also lets a migration that wrote the row and died before the file write still finish. Public pads are unaffected: a pad anyone holding its id can read is no more reachable for having a file bound to it.

Refused imports surface as HTTP 403 with `code: "legacy_protected_import_disabled"`; an id the app cannot link gets the 400 above instead, as it is refused before the switch is asked. The `.pad` file is left untouched, so switching the setting back on migrates it on the next open.

## Audit log

Every branch emits one log line at `info` level (or `warning` for a refusal); a migration that lost a race for the pad id writes a second, `info`, before the collision's. Where the migration stops on something else, the error mapper writes the line instead. A pad on another server takes the path of `POST /api/v1/pads/from-url`, whose own refusals - external pads switched off, say - it logs at `debug`. A group listing Etherpad refuses for another reason than not knowing the group is a warning once a minute for each file, an Etherpad that does not answer a warning once a minute, a key Etherpad rejects an error once a minute for the instance, and a race lost without a winner an error (`Could not create pad binding.`). Fields:

| Field | Meaning |
|---|---|
| `fileId` | NC file ID being migrated |
| `sourceHost` | Scheme and host of the legacy shortcut's URL |
| `originBranch` | `same` or `cross` |
| `accessMode` | `public` / `protected` / unset for cross-origin |
| `collision` | `none` / `with_access` / `no_access` / `self`; absent where no row was looked up and decided - an id the app cannot link, the line of a lost race |
| `uid` | Migrating user |
| `reason` | Why a pad cannot be linked: `invalid_id`, `case_variant` or `not_in_group`; on that refusal only |
| `boundFileId` | The file a row already binds the pad to, on `with_access` and `no_access`; on `case_variant`, the file a row binds it to under other capitals |

Neither the URL nor the pad id is written out. A legacy shortcut can carry
credentials in its userinfo and a token in its query, and for a public pad the
path is the permission - `sourceHost` plus the pad id would put that link back
together. To go from a log line to the pad, read `pad_id` from `ep_pad_bindings`
for that `file_id`.

Grep `app:etherpad_nextcloud` + `legacy Ownpad` in `nextcloud.log` to reconstruct what was imported when. `Migrated legacy Ownpad` alone finds the successes only - every refusal is worded `Refused legacy Ownpad migration`.

## Out of scope

- **Admin bulk-migration CLI / `occ` command.** Each file migrates on its own first-open while `allow_legacy_protected_import` is on, which is the arrangement for an instance that does not share its Etherpad server. An instance that does share one has no supported way to take over protected Ownpad files: switching the policy on for a while is not that way, because the forgery is open to every user for as long as it is on. Such a command — an admin converting files whose origin they can vouch for, without opening the path to everyone — is what would close that gap.
- **Public → protected conversion of an already-managed pad.** Etherpad's pad-id format encodes the type and can't be renamed in place; converting would require content copy + a new pad. If needed, that lives in a separate "Convert pad access mode" feature.
- **Real-time mirroring** between legacy and current pads.

## Status returned by `/api/v1/pads/initialize*`

When the migration ran, the endpoint returns `status: "migrated_from_legacy"` (instead of the regular `initialized`). The frontend logs this to the browser console on first open; a user-facing toast is not currently surfaced (the codebase has no notification framework wired in).
