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
registerHandler({ id: VIEWER_HANDLER_ID, mimes: [MIME], component })
