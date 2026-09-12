/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

export const APP_ID = 'etherpad_nextcloud'
export const MIME = 'application/x-etherpad-nextcloud'
export const VIEWER_HANDLER_ID = 'etherpad_nextcloud'

/** The kinds of pad this app makes. PadAccessMode.php is the same list. */
export const PAD_ACCESS_MODES = Object.freeze(['protected', 'public'])

/** What a caller gets for asking for none. PadCreateController spells it too. */
export const DEFAULT_PAD_ACCESS_MODE = 'protected'

/**
 * @param {unknown} value
 * @return {boolean}
 */
export const isPadAccessMode = (value) => PAD_ACCESS_MODES.includes(value)
