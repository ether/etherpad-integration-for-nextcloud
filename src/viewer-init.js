/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Registers the pad MIME handler with the Viewer. */

import { registerHandler } from '@nextcloud/viewer'

import { MIME, VIEWER_HANDLER_ID } from './lib/constants.js'
import component from './viewer-main.js'

// The options object itself, not a loader for it: the Viewer assigns its
// Mime mixin onto what it is given and registers it under `component.name`,
// and a function silently takes neither.
const handler = { id: VIEWER_HANDLER_ID, mimes: [MIME], component }

registerHandler(handler)

/**
 * Offer the handler the way a Viewer before 31.0.9 expects it.
 *
 * `registerHandler()` writes to a global that those versions never read, so
 * on them the pad would fall through to a plain download with nothing
 * logged. Newer ones copy that global in when they mount, and the check for
 * an already-registered id then makes this a no-op.
 */
let attempts = 0
const registerWithRunningViewer = () => {
	attempts += 1
	if (typeof window.OCA?.Viewer?.registerHandler !== 'function') {
		if (attempts < 20) {
			window.setTimeout(registerWithRunningViewer, 500)
		}
		return
	}
	if (Array.isArray(window.OCA.Viewer.availableHandlers)
		&& window.OCA.Viewer.availableHandlers.some((registered) => registered?.id === VIEWER_HANDLER_ID)) {
		return
	}
	window.OCA.Viewer.registerHandler(handler)
}

registerWithRunningViewer()
