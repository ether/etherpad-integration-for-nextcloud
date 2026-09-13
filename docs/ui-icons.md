# UI Icons (Menu + Viewer/Sidebar)

SPDX-License-Identifier: AGPL-3.0-or-later

This note describes how icons are wired in the `+ New` menu and in pad sync actions.

## Icon Files

- `img/etherpad-icon-black.svg`
  - Admin settings (dark/monochrome icon)
- `img/etherpad-icon-color.svg`
  - Template creator, `+ New` entries
- `img/filetypes/etherpad-nextcloud-pad.svg`
  - preferred Nextcloud mimetype icon source for MIME alias `etherpad-nextcloud-pad`
  - kept identical to `img/etherpad-icon-color.svg`

## `+ New` Menu (Submenu Entries)

### 1) Native "New pad" integration

- File: `lib/Listeners/RegisterTemplateCreatorListener.php`
- Icon is set inline via `setIconSvgInline(...)`.
- Source: `img/etherpad-icon-color.svg`.

### 2) Template picker tiles

- File: `lib/Template/PadTemplateProvider.php`
- The "Public pad" and "Public pad from URL" tiles live in Nextcloud's own
  template picker behind `New pad`.
- Each takes its icon from `setCustomPreviewUrl()`; without it the marker files
  in appdata would show the generic document icon.

## File List / File Type Icon (`.pad`)

Two different mechanisms put a picture next to a `.pad`, and which one a
view uses decides what is shown.

- **Where a preview is asked for** – the file list, public folder shares,
  unified search, dashboard tiles – `PadPreviewProvider` answers with
  `img/preview-fallback.png`, the pad glyph. This is the app's own icon and
  it lives inside the app.
- **In a full-text search result** – which carries no preview –
  `FullTextSearchResultListener` replaces the icon Files FullTextSearch chose
  with the app's own, through the `Files_FullTextSearch.onSearchResult`
  extension event that fires right after that choice.
- **Everywhere else without a preview** – file-picker dialogs, for example –
  Nextcloud resolves the MIME alias to `core/img/filetypes/{alias}.svg` and
  looks nowhere else. The alias is `text`: a pad is a text document, and
  `x-office/document` was declined deliberately because it would also file
  pads under the Files type filter's "Documents" (#27, #130).

There is no public API for an app to register a file-type icon of its own
(nextcloud/server#52742). Copying one into `core/img/filetypes/` works, and
earlier versions of this app did it, but core is signed: the file is reported
as an extra file by `occ integrity:check-core` and is removed by the next
server upgrade. `RegisterMimeType` now takes such a leftover back out again,
unless its content differs from the icon this app ships – then it belongs to
whoever put it there and is only reported.

`themes/<theme>/core/img/filetypes/` is not a way around this. `imagePath()`
does look there, and `OC_Util::getTheme()` falls back to `default` when
`themes/default` exists, but `MimeIconProvider::searchfileName()` reads the
`theme` system value directly and skips the theme path when it is empty.

Important after icon changes:

1. `occ app:disable etherpad_nextcloud && occ app:enable etherpad_nextcloud`
2. `occ maintenance:mimetype:update-js`
3. `occ maintenance:mimetype:update-db --repair-filecache`

A preview is cached per file and does not change with a new app version, so
`occ preview:cleanup` is needed to see a changed `preview-fallback.png`.

## Sync Actions (Authenticated Files Flow)

- File: `src/viewer-main.js`
- Registers the native `.pad` viewer component and handles pad open/sync lifecycle.
- Auto-sync runs every two minutes while the viewer is mounted and on `pagehide`; there is no UI affordance for manual sync.
