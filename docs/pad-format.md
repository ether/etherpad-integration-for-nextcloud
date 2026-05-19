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
- Viewer/API responses never expose stored HTML directly. `SnapshotExtractor` runs `SnapshotHtmlSanitizer`, which allowlists simple formatting tags only and drops every attribute before HTML reaches the frontend.

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
- `withExportSnapshot(...)` updates export metadata + snapshot body
- `withStateAndSnapshot(...)` updates state + snapshot
- `getTextSnapshotForRestore(...)` and `getHtmlSnapshotForRestore(...)` read stored snapshots from the body

Snapshot write flow:

- `PadFileService::withExportSnapshot(...)` builds the new `.pad` content after an Etherpad export.
- `PadFileLockRetryService::putContentWithSyncLockRetry(...)` writes that content back to the Nextcloud file with bounded lock retry.
- `SnapshotExtractor` is read-only: it extracts text + sanitized HTML for viewer responses and does not mutate `.pad` files.
- External public pad create/sync paths both use the validated, host-pinned `/export/txt` fetch internally and store no HTML snapshot:
  - create uses `EtherpadClient::normalizeAndFetchExternalPublicPadTextOrEmpty(...)`, allowing the `.pad` file to be created with an empty initial snapshot if the export is not available yet.
  - sync uses `EtherpadClient::normalizeAndFetchExternalPublicPadText(...)`, keeping later export failures visible.

## Sync Semantics

- Sync writes only when the upstream snapshot actually differs.
- `force=1` requests an immediate upstream re-check, but unchanged snapshots are still not rewritten.
- On successful sync:
  - `snapshot_rev` is updated
  - body is replaced:
    - internal pads: current text + HTML
    - external pads: current text, no HTML
