# UI Icons (Menu + Viewer/Sidebar)

SPDX-License-Identifier: AGPL-3.0-or-later

This note describes how icons are wired in the `+ New` menu and in pad sync actions.

## Icon Files

- `img/etherpad-icon-black.svg`
  - Admin settings (dark/monochrome icon)
- `img/etherpad-icon-color.svg`
  - File list, template creator, `+ New` entries
- `img/filetypes/etherpad-nextcloud-pad.svg`
  - preferred Nextcloud mimetype icon source for MIME alias `etherpad-nextcloud-pad`
  - kept identical to `img/etherpad-icon-color.svg`

## `+ New` Menu (Submenu Entries)

### 1) Native "New pad" integration

- File: `lib/Listeners/RegisterTemplateCreatorListener.php`
- Icon is set inline via `setIconSvgInline(...)`.
- Source: `img/etherpad-icon-color.svg`.

### 2) Custom "Public pad" entry

- File: `src/files/public-pad-menu.js`
- Entry is injected dynamically into the menu.
- Icon reuses the same file type class as `.pad`:
  - `icon-filetype-etherpad-nextcloud-pad`

## File List / File Type Icon (`.pad`)

- Filetype icon rendering is native Nextcloud (`core/img/filetypes/{alias}.svg`).
- Alias used by this app: `etherpad-nextcloud-pad`.
- App source icon: `img/filetypes/etherpad-nextcloud-pad.svg`.
- `RegisterMimeType` repair step synchronizes this icon into core filetypes so native sizing/spacing matches other file types (for example `.md`).

Important after icon changes:

1. `occ app:disable etherpad_nextcloud && occ app:enable etherpad_nextcloud`
2. `occ maintenance:mimetype:update-js`
3. `occ maintenance:mimetype:update-db`

## Sync Actions (Authenticated Files Flow)

- File: `src/viewer-main.js`
- Registers the native `.pad` viewer component and handles pad open/sync lifecycle.
- Auto-sync runs every two minutes while the viewer is mounted and on `pagehide`; there is no UI affordance for manual sync.
