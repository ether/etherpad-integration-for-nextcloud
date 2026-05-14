/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { isFilesAppRoute } from './lib/nextcloud-runtime.js'
import { registerOpenAction } from './files/open-action.js'
import { createPadOpener } from './files/pad-opener.js'
import { createPublicPadFlows } from './files/public-pad-create-flow.js'
import { createPublicPadMenuRegistrar } from './files/public-pad-menu.js'
import { registerPublicSharePadClickInterceptor } from './files/public-share-pad-links.js'
import { schedulePublicSingleShareUiStateRefresh } from './files/public-single-share-ui.js'
import { createRouteController } from './files/route-controller.js'

(function () {
	let booted = false
	const openPadInNativeViewer = createPadOpener()
	const publicPadFlows = createPublicPadFlows({ openPadInNativeViewer })

	const ensurePublicPadMenuRegistration = createPublicPadMenuRegistrar({
		isFilesAppRoute,
		onCreateInternalPublicPad: publicPadFlows.createInternalPublicPad,
		onCreateExternalPublicPad: publicPadFlows.createExternalPublicPad,
	})
	const routes = createRouteController({
		ensurePublicPadMenuRegistration,
		openPadInNativeViewer,
		schedulePublicSingleShareUiStateRefresh,
	})

	const boot = () => {
		if (booted) {
			return
		}
		booted = true
		routes.installRouteWatchers()
		routes.evaluateCurrentRoute()
		registerOpenAction({ openPadInNativeViewer })
		registerPublicSharePadClickInterceptor({ openPadInNativeViewer })
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true })
	} else {
		boot()
	}
})()
