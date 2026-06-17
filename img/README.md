# Image Assets and Licenses

SPDX-License-Identifier: AGPL-3.0-or-later

This folder contains icon assets used by the app UI and filetype integration.

## License Mapping

- `etherpad-icon-black.svg`
- `etherpad-icon-color.svg`
- `etherpad-icon-white.svg`
- `etherpad_nextcloud.svg`
- `etherpad_nextcloud-dark.svg`
- `etherpad_nextcloud-512.png`
- `filetypes/etherpad-nextcloud-pad.svg`
  - Source: Etherpad branding assets
  - License: Apache License 2.0
  - License text: `../LICENSES/Apache-2.0.txt`

- `pad-debug.svg`
  - Local debug/development icon
  - License: AGPL-3.0-or-later

## Notes

- `filetypes/etherpad-nextcloud-pad.svg` is intentionally kept in sync with
  `etherpad-icon-color.svg` to ensure consistent `.pad` icon rendering in
  Nextcloud filetype fallbacks.

- `etherpad_nextcloud.svg` / `etherpad_nextcloud-dark.svg` follow the
  Nextcloud `img/<app-id>.svg` naming convention so the app icon is
  auto-discovered by the App Store and Files UI without needing an
  explicit `<icon>` element in `appinfo/info.xml`. Artwork is identical
  to `etherpad-icon-black.svg` / `etherpad-icon-white.svg` respectively;
  duplicated rather than aliased so it survives release archive
  generation cleanly on every platform.

- `etherpad_nextcloud-512.png` is a 512×512 PNG rasterization of the
  black variant with a transparent background, suitable for manual
  upload to the apps.nextcloud.com developer profile / store listing.
