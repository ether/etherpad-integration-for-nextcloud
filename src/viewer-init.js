/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/** Registers the pad MIME handler and lazily loads its Viewer component. */

import { registerHandler } from '@nextcloud/viewer'

import { MIME, VIEWER_HANDLER_ID } from './lib/constants.js'

registerHandler({
	id: VIEWER_HANDLER_ID,
	mimes: [MIME],
	component: () => import('./viewer-main.js'),
})
