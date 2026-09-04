/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { hasNativeViewer } from '../lib/nextcloud-runtime.js'
import {
	isPadName,
	parsePadPathFromDavHref,
	parsePublicSharePadFromHref,
	parsePublicShareTokenFromLocation,
	viewerUrlForPublicShare,
} from '../lib/urls.js'

export const resolvePublicSharePadPathFromLink = (link, publicToken) => {
	if (!(link instanceof HTMLAnchorElement) || !publicToken) {
		return null
	}
	const href = link.getAttribute('href') || ''
	const publicSharePad = parsePublicSharePadFromHref(href)
	if (publicSharePad && publicSharePad.token === publicToken && isPadName(publicSharePad.path)) {
		return publicSharePad.path
	}
	const davPadPath = parsePadPathFromDavHref(href)
	if (isPadName(davPadPath)) {
		return davPadPath
	}
	return null
}

export const registerPublicSharePadClickInterceptor = ({ openPadInNativeViewer }) => {
	if (window.OCA && window.OCA.EtherpadNextcloudClickInterceptorRegistered === true) {
		return
	}
	if (window.OCA) {
		window.OCA.EtherpadNextcloudClickInterceptorRegistered = true
	}

	const maybeOpenPad = async (event) => {
		if (!event || event.defaultPrevented) {
			return
		}
		const publicToken = parsePublicShareTokenFromLocation()
		if (!publicToken) {
			return
		}
		if (event.type === 'click' && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
			return
		}
		if (!(event.target instanceof Element)) {
			return
		}
		const link = event.target.closest('a[href]')
		if (!(link instanceof HTMLAnchorElement)) {
			return
		}
		const padPath = resolvePublicSharePadPathFromLink(link, publicToken)
		if (!padPath) {
			return
		}
		event.preventDefault()
		event.stopPropagation()
		if (typeof event.stopImmediatePropagation === 'function') {
			event.stopImmediatePropagation()
		}
		if (hasNativeViewer()) {
			await openPadInNativeViewer(padPath)
			return
		}
		window.location.assign(viewerUrlForPublicShare(publicToken, padPath))
	}

	document.addEventListener('click', maybeOpenPad, true)
}
