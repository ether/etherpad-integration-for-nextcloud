# .pad Format v1

SPDX-License-Identifier: AGPL-3.0-or-later

## Overview

File format: `etherpad-nextcloud/1`

The `.pad` file consists of:

1. YAML frontmatter (metadata)
2. Snapshot body with text and optional HTML

## Frontmatter Schema

Required fields:

- `format`
- `file_id`
- `pad_id`
- `access_mode` (`public|protected`)
- `state` (`active`; legacy files may still contain `trashed` or `purged`)
- `created_at` (ISO8601)
- `updated_at` (ISO8601)
- `snapshot_rev` (int, `-1` before first successful sync)

Additional fields:

- `deleted_at` (`null` or ISO8601)
- `pad_url` (optional, absolute `http(s)` URL)
- `pad_origin` (optional, origin of external Etherpad server, e.g. `https://pad.example.org`)
- `remote_pad_id` (optional, actual pad ID on external server)
- `alias_of_pad_id` (optional, pad this file defers to instead of binding one of its own)

Example:

```yaml
---
format: "etherpad-nextcloud/1"
file_id: 994
pad_id: "g.TmDeyA334sIq2LQh$p-4k9x2m7q8r1t5v6n3d0c"
access_mode: "protected"
state: "active"
deleted_at: null
created_at: "2026-03-05T00:40:36+00:00"
updated_at: "2026-03-05T11:10:21+00:00"
snapshot_rev: 42
---
```

Legacy migration:

- Old Ownpad format
  - `[InternetShortcut]`
  - `URL=https://.../p/<pad-id>`
  - is auto-migrated on first open to `etherpad-nextcloud/1`. The migration branches on the URL origin (same vs. cross) and the pad-id format; GroupPad IDs (`g.<group>$<name>`) re-bind as protected, free-form IDs re-bind as public, cross-origin URLs route through the external-pad flow as `ext.*`.
  - A claim-collision check protects against legacy files being used to claim pads already bound to another user's file — see `docs/legacy-ownpad-migration.md` for the full state table.

## Alias Files

A `.pad` copied inside Nextcloud is a byte copy: it carries the original's `pad_id` and a `file_id` that is no longer its own, and it has no binding row. Opening it therefore fails with `missing_binding` and lands on the recovery card, which offers to open the original or to fork the stored snapshot into a new pad.

`alias_of_pad_id` records that the user chose the first answer permanently. It is written only through the recovery card's opt-in, never implicitly on copy, and it names the pad rather than a file id so it survives a rename or move of the original. Because a copy starts out with the original's `pad_id`, the marker normally repeats that value; it differs only if this file later gets a pad of its own.

On open, a file with the marker and no binding resolves the marker to its bound file and opens that file instead. The requester must already be able to read the target — the same `UserNodeResolver` round trip the find-original lookup uses — and every miss falls back to the ordinary recovery card, so a hand-written marker cannot be used to probe for pads in other accounts. Exactly one hop is followed: the file an alias points at is opened as itself, which also makes a cycle unreachable.

**The snapshot in an alias file stays frozen.** Sync resolves the file its binding names, so it only ever writes the original; the copy keeps the snapshot it held when it was made. Two consequences follow and are accepted rather than worked around:

- A WebDAV download of an alias file returns that old snapshot, not the current pad.
- If the original is deleted, the alias is left with a stale snapshot and no live pad. The recovery card returns, and forking from it seeds a new pad with content from the moment of the copy.

Mirroring the snapshot into every alias was considered and rejected: sync would first have to find the aliases, which needs an index of them in the database — precisely the state this design keeps in the file.

## Snapshot Body

Body layout:

```text
[TEXT]
<plain text snapshot>
[HTML-BEGIN]
<html snapshot>
[HTML-END]
```

Notes:

- Text is the primary restore snapshot.
- HTML is an additional structure/format snapshot.
- External pads (`pad_origin` + `remote_pad_id`) are imported and synced as text only for security reasons; HTML sections are omitted when the app writes external snapshots.
- The parser expects exact `[HTML-BEGIN] ... [HTML-END]` markers for the HTML part.
- Viewer/API responses never expose stored HTML. The read-only viewer is served by `LivePadHtmlFetcher`, which fetches the pad's current HTML and runs `SnapshotHtmlSanitizer` over it. That sanitizer allowlists simple formatting tags and drops every attribute **except `href` on `<a>`**, which survives only for `http`, `https` and `mailto`; the browser applies the same allowlist again before the HTML is injected.

## Mode Variants

- Internal + Protected
  - `access_mode: protected`
  - `pad_id`: GroupPad (`g.<group>$<name>`)
  - `pad_url`: internal Etherpad URL
- Internal + Public
  - `access_mode: public`
  - `pad_id`: public pad ID (for example `nc-...`)
  - `pad_url`: internal Etherpad URL
- External + Public
  - `access_mode: public`
  - `pad_id`: external marker (`ext.<remote_pad_id>`)
  - `pad_origin` + `remote_pad_id` are set
  - `pad_url`: external URL used for viewer open
  - no row in `ep_pad_bindings`; the `.pad` frontmatter is the source of truth for the remote target

Protected + external is not supported.

## Lifecycle State Semantics

- `active`
  - normal editing state
- `trashed` / `purged`
  - legacy parser compatibility only; new writes do not use these states

The DB binding table uses `active` and `pending_delete`. Successful trash deletes
the binding row; restore can recreate it from the `.pad` frontmatter and snapshot.
External pads are not managed in the DB binding table, so trash/restore only moves
the Nextcloud file and never creates, deletes, or restores anything on the remote
Etherpad server.

## Parsing/Serializing

Implementation: `lib/Service/PadFileService.php`

- `parsePadFile(string $content): array{frontmatter, body}`
- `serialize(array $frontmatter, string $body): string`
- `readPad(string $content): ParsedPadFile` parses once and hands back the frontmatter, the body and the fields derived from them
- `withExportSnapshot(ParsedPadFile $pad, PadSnapshot $snapshot)` updates export metadata + snapshot body
- `withRestoredSnapshot(ParsedPadFile $pad, ...)` writes the document a restore leaves behind: active, undeleted, pointed at the replacement pad, with both snapshot halves as given
- `getSnapshotPartsFromBody(string $body): array{text, html}` splits a stored snapshot into its two halves, from a body a caller already has
- `buildInitialDocument(...)` takes an optional `PadSnapshot` for a document that starts out with content; without one the document is unsnapshotted (`snapshot_rev: -1`) and its body is empty
- `PadSnapshot` is text, an HTML half and the revision the snapshot was taken at. Its `html` is nullable, and the two empty cases write different files: `null` means a text-only snapshot and omits the `[HTML-BEGIN]`/`[HTML-END]` section entirely, `''` writes the section with nothing in it. A negative revision is refused — `-1` is what an unsnapshotted document uses

Snapshot write flow:

- `PadFileService::withExportSnapshot(...)` builds the new `.pad` content after an Etherpad export.
- `PadFileLockRetryService::putContentWithSyncLockRetry(...)` writes that content back to the Nextcloud file with bounded lock retry.
- Stored snapshots are read by `LifecycleService` when restoring a pad and by the forced sync when comparing content. No viewer path reads them.
- External public pad create/sync paths both use the validated, host-pinned `/export/txt` fetch internally (via `ExternalPadExportFetcher`) and store no HTML snapshot:
  - create uses `ExternalPadExportFetcher::normalizeAndFetchExternalPublicPadTextOrEmpty(...)`, allowing the `.pad` file to be created with an empty initial snapshot if the export is not available yet.
  - sync uses `ExternalPadExportFetcher::normalizeAndFetchExternalPublicPadText(...)`, keeping later export failures visible.

## Sync Semantics

- Sync writes only when the upstream snapshot actually differs.
- `force=1` requests an immediate upstream re-check, but unchanged snapshots are still not rewritten.
- On successful sync:
  - `snapshot_rev` is updated
  - body is replaced:
    - internal pads: current text + HTML
    - external pads: current text, no HTML
