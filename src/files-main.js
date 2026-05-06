/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID, MIME } from './lib/constants.js'
import {
	ocGenerateUrl,
	ocImagePath,
	ocPermissionRead,
} from './lib/oc-compat.js'
import {
	apiCreatePadFromUrl,
	apiCreatePublicPad,
	apiResolvePadByFileId,
	apiResolvePadByPath,
} from './lib/api-client.js'
import { hasNativeViewer, isFilesAppRoute } from './lib/nextcloud-runtime.js'
import {
	filesUrlForFileId,
	getCurrentDir,
	isPadName,
	normalizeFilePath,
	parseFileIdFromCurrentLocation,
	parseFileIdFromFilesHref,
	parsePadPathFromDavHref,
	parsePublicSharePadFromHref,
	parsePublicShareTokenFromLocation,
	resolveOpenDir,
	viewerUrlForPath,
	viewerUrlForPublicShare,
} from './lib/urls.js'
import { openCreatedPadInViewer } from './files/created-pad-opener.js'
import { createSidebarSyncController } from './files/sidebar-sync.js'
import { createPublicPadMenuRegistrar } from './files/public-pad-menu.js'
import {
	openExternalPublicPadDialog,
	openInternalPublicPadDialog,
	openPublicPadModeDialog,
} from './files/pad-create-dialogs.js'
import { isSingleFilePublicShare, schedulePublicSingleShareUiStateRefresh } from './files/public-single-share-ui.js'

(function () {
	const OPEN_ACTION = 'etherpad_nextcloud_open'
	let booted = false
	let lastOpenKey = null
	let lastOpenAt = 0
	let lastRouteCheckKey = ''
	const sidebarSync = createSidebarSyncController()

	const parseNumericFileId = (value) => {
		const id = Number(value)
		return Number.isFinite(id) && id > 0 ? id : null
	}

	const readFileIdCandidate = (source) => {
		if (!source) {
			return null
		}
		const directCandidates = [
			source.fileId,
			source.fileid,
			source.id,
			source.nodeId,
			source.nodeid,
			source.file_id,
		]
		for (const candidate of directCandidates) {
			const parsed = parseNumericFileId(candidate)
			if (parsed !== null) {
				return parsed
			}
		}
		if (typeof source.get === 'function') {
			for (const key of ['fileid', 'fileId', 'id']) {
				try {
					const parsed = parseNumericFileId(source.get(key))
					if (parsed !== null) {
						return parsed
					}
				} catch (error) {
					// Ignore model getter errors and keep probing other shapes.
				}
			}
		}
		return null
	}

	const parseFileIdFromElement = (element) => {
		if (!(element instanceof Element)) {
			return null
		}
		const selfCandidates = [
			element.getAttribute('data-fileid'),
			element.getAttribute('data-file-id'),
			element.getAttribute('data-id'),
			element.getAttribute('data-node-id'),
		]
		for (const candidate of selfCandidates) {
			const parsed = parseNumericFileId(candidate)
			if (parsed !== null) {
				return parsed
			}
		}
		const hrefNode = element.matches('a[href]') ? element : element.querySelector('a[href]')
		if (hrefNode instanceof HTMLAnchorElement) {
			return parseFileIdFromFilesHref(hrefNode.getAttribute('href'))
		}
		return null
	}

	const extractFileIdFromActionContext = (context) => {
		if (!context || typeof context !== 'object') {
			return null
		}
		const sources = [
			context,
			context.fileInfoModel,
			context.fileInfo,
			context.model,
			context.node,
			context.file,
			context.attributes,
		]
		for (const source of sources) {
			const parsed = readFileIdCandidate(source)
			if (parsed !== null) {
				return parsed
			}
		}
		const elementCandidates = [
			context.fileElement,
			context.el,
			context.element,
			context.$file && context.$file[0],
			context.$el && context.$el[0],
			context.target,
			context.currentTarget,
		]
		for (const candidate of elementCandidates) {
			const parsed = parseFileIdFromElement(candidate)
			if (parsed !== null) {
				return parsed
			}
		}
		return null
	}

	const createInternalPublicPad = async () => {
		const inputName = await openInternalPublicPadDialog()
		if (!inputName) {
			return
		}
		const name = inputName.trim()
		const normalizedName = name.toLowerCase().endsWith('.pad') ? name : (name + '.pad')
		const filePath = normalizeFilePath(getCurrentDir(), normalizedName)

		try {
			const created = await apiCreatePublicPad(filePath)
			const createdPath = (created && typeof created.file === 'string') ? created.file : filePath
			const createdFileId = created && Number.isFinite(Number(created.file_id)) ? Number(created.file_id) : null
			await openCreatedPadInViewer(
				{
					path: createdPath,
					fileId: createdFileId,
				},
				{
					fallbackOpen: openPadInNativeViewer,
					resolveOpenDir,
				}
			)
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not create public pad.')
			window.alert(message)
		}
	}

	const promptAndCreatePadFromUrl = async () => {
		const values = await openExternalPublicPadDialog()
		if (!values) {
			return
		}
		const trimmedUrl = values.padUrl.trim()
		const name = values.name.trim()
		const dir = getCurrentDir()
		const normalizedName = name.toLowerCase().endsWith('.pad') ? name : (name + '.pad')
		const filePath = normalizeFilePath(dir, normalizedName)

		try {
			const created = await apiCreatePadFromUrl(filePath, trimmedUrl)
			const createdPath = (created && typeof created.file === 'string') ? created.file : filePath
			const createdFileId = created && Number.isFinite(Number(created.file_id)) ? Number(created.file_id) : null
			await openCreatedPadInViewer(
				{
					path: createdPath,
					fileId: createdFileId,
				},
				{
					fallbackOpen: openPadInNativeViewer,
					resolveOpenDir,
				}
			)
		} catch (error) {
			const message = error instanceof Error ? error.message : t(APP_ID, 'Could not import public pad URL.')
			window.alert(message)
		}
	}

	const promptAndCreatePublicPad = async () => {
		const choice = await openPublicPadModeDialog()
		if (choice === 'internal') {
			await createInternalPublicPad()
			return
		}
		if (choice === 'external') {
			await promptAndCreatePadFromUrl()
		}
	}

	const navigateFilesRouteAndOpen = (fileId, path) => {
		const router = window.OCP && window.OCP.Files && window.OCP.Files.Router
		if (!router || typeof router.goToRoute !== 'function') {
			return false
		}
		if (!fileId || !Number.isFinite(fileId)) {
			return false
		}
		router.goToRoute(
			null,
			{
				view: 'files',
				fileid: String(fileId),
			},
			{
				dir: resolveOpenDir(path),
				editing: 'false',
				openfile: 'true',
			}
		)
		window.setTimeout(() => {
			const hasExpectedPath = (window.location.pathname || '').includes('/apps/files/files/' + String(fileId))
			if (hasExpectedPath) {
				return
			}
			const fallbackUrl = filesUrlForFileId(fileId, path)
			window.location.assign(fallbackUrl)
		}, 180)
		return true
	}

	const openPadInNativeViewer = async (navigation) => {
		const openKey = String(navigation.fileId ?? '') + '|' + String(navigation.path ?? '')
		const now = Date.now()
		if (lastOpenKey === openKey && (now - lastOpenAt) < 800) {
			return
		}
		lastOpenKey = openKey
		lastOpenAt = now

		const publicShareToken = parsePublicShareTokenFromLocation()
		const inPublicShareRoute = publicShareToken !== null && publicShareToken !== ''

		const fallbackOpen = () => {
			if (inPublicShareRoute) {
				window.location.assign(viewerUrlForPublicShare(publicShareToken, navigation.path || ''))
				return
			}
			if (isFilesAppRoute() && navigation.fileId !== null && navigation.fileId !== undefined && Number.isFinite(Number(navigation.fileId))) {
				const fallbackPath = navigation.path || '/'
				window.location.assign(filesUrlForFileId(Number(navigation.fileId), fallbackPath))
				return
			}
			const routeFileId = isFilesAppRoute() ? parseFileIdFromCurrentLocation() : null
			if ((navigation.fileId === null || navigation.fileId === undefined) && routeFileId) {
				const fallbackPath = navigation.path || '/'
				window.location.assign(filesUrlForFileId(routeFileId, fallbackPath))
				return
			}
			if (navigation.fileId !== null && navigation.fileId !== undefined && Number.isFinite(Number(navigation.fileId))) {
				const fallbackPath = navigation.path || '/'
				window.location.assign(filesUrlForFileId(Number(navigation.fileId), fallbackPath))
				return
			}
			if (navigation.path) {
				window.location.assign(viewerUrlForPath(navigation.path))
			}
		}

		if (!hasNativeViewer()) {
			fallbackOpen()
			return
		}

		let path = navigation.path || ''
		let fileId = navigation.fileId ?? null
		if (!path && navigation.fileId !== null && navigation.fileId !== undefined) {
			try {
				const resolvedPad = await apiResolvePadByFileId(navigation.fileId)
				path = (resolvedPad && typeof resolvedPad.path === 'string') ? resolvedPad.path : ''
				fileId = (resolvedPad && Number.isFinite(Number(resolvedPad.file_id))) ? Number(resolvedPad.file_id) : fileId
			} catch (e) {
				path = ''
			}
		}

		if (!path) {
			fallbackOpen()
			return
		}
		if ((!fileId || !Number.isFinite(Number(fileId))) && path && !inPublicShareRoute) {
			try {
				const resolvedPad = await apiResolvePadByPath(path)
				fileId = (resolvedPad && Number.isFinite(Number(resolvedPad.file_id))) ? Number(resolvedPad.file_id) : fileId
			} catch (e) {
				// resolve failure is handled by route fallback below
			}
		}
		if ((!fileId || !Number.isFinite(Number(fileId))) && isFilesAppRoute()) {
			const routeFileId = parseFileIdFromCurrentLocation()
			if (routeFileId) {
				fileId = routeFileId
			}
		}
		if (isFilesAppRoute()) {
			if (fileId && Number.isFinite(Number(fileId)) && navigateFilesRouteAndOpen(Number(fileId), path)) {
				return
			}
			fallbackOpen()
			return
		}

		try {
			window.OCA.Viewer.open({ path })
		} catch (e) {
			fallbackOpen()
		}
	}

	const resolvePublicSharePadPathFromLink = (link, publicToken) => {
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

	const registerPadClickInterceptor = () => {
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
				await openPadInNativeViewer({ path: padPath, fileId: null })
				return
			}
			window.location.assign(viewerUrlForPublicShare(publicToken, padPath))
		}

		document.addEventListener('click', maybeOpenPad, true)
	}

	const registerOpenAction = () => {
		if (!(window.OCA && window.OCA.Files && window.OCA.Files.fileActions)) {
			return
		}
		window.OCA.Files.fileActions.register(
			MIME,
			OPEN_ACTION,
			ocPermissionRead(),
			ocImagePath(APP_ID, 'etherpad-icon-color'),
			(filename, context) => {
				const dir = (context && context.dir) || getCurrentDir()
				const filePath = normalizeFilePath(dir, filename)
				const fileId = extractFileIdFromActionContext(context)
				void openPadInNativeViewer({ path: filePath, fileId })
			},
			t(APP_ID, 'Open in Etherpad')
		)
		window.OCA.Files.fileActions.setDefault(MIME, OPEN_ACTION)
	}

	const ensurePublicPadMenuRegistration = createPublicPadMenuRegistrar({
		isFilesAppRoute,
		onCreatePublicPad: promptAndCreatePublicPad,
	})

	const maybeRedirectFromFilesRoute = async () => {
		if (hasNativeViewer()) {
			return
		}
		const fileId = parseFileIdFromCurrentLocation()
		if (!fileId) {
			return
		}
		const params = new URLSearchParams(window.location.search || '')
		if (params.get('epNoRedirect') === '1' || params.get('details') === '1' || params.get('opendetails') === 'true') {
			return
		}
		if ((window.location.pathname || '').includes('/apps/' + APP_ID + '/')) {
			return
		}
		try {
			const resolved = await apiResolvePadByFileId(fileId)
			if (resolved && resolved.is_pad && resolved.viewer_url) {
				window.location.assign(resolved.viewer_url)
			}
		} catch (e) {
			// Ignore and let Files handle non-pad files
		}
	}

	const maybeNormalizeStalePadFileRoute = async () => {
		if (hasNativeViewer()) {
			return
		}
		if (!isFilesAppRoute()) {
			return
		}
		const fileId = parseFileIdFromCurrentLocation()
		if (!fileId) {
			return
		}
		const params = new URLSearchParams(window.location.search || '')
		if (params.get('openfile') === 'true' || params.get('opendetails') === 'true' || params.get('details') === '1') {
			return
		}
		try {
			const resolved = await apiResolvePadByFileId(fileId)
			if (!resolved || !resolved.is_pad) {
				return
			}
			const dir = params.get('dir') || '/'
			const target = ocGenerateUrl('/apps/files/files') + '?dir=' + encodeURIComponent(dir)
			if (window.location.href !== target) {
				window.location.assign(target)
			}
		} catch (e) {
			// keep current route if resolve fails
		}
	}

	const maybeRedirectFromPublicShareRoute = () => {
		const token = parsePublicShareTokenFromLocation()
		if (!token) {
			return
		}
		const params = new URLSearchParams(window.location.search || '')
		const fileName = params.get('files') || ''
		if (hasNativeViewer()) {
			if (!isPadName(fileName)) {
				return
			}
			const dir = params.get('path') || '/'
			const filePath = normalizeFilePath(dir, fileName)
			void openPadInNativeViewer({ path: filePath, fileId: null })
			return
		}
		if (!isPadName(fileName)) {
			const isAlreadyInAppViewer = (window.location.pathname || '').includes('/apps/' + APP_ID + '/public/')
			if (isAlreadyInAppViewer) {
				return
			}
			// Only single-file shares can be opened without an explicit file path.
			// For folder shares, an empty redirect causes a first-open 400 ("No .pad file selected").
			if (isSingleFilePublicShare(token)) {
				window.location.assign(viewerUrlForPublicShare(token, ''))
			}
			return
		}
		const dir = params.get('path') || '/'
		const filePath = normalizeFilePath(dir, fileName)
		window.location.assign(viewerUrlForPublicShare(token, filePath))
	}

	const evaluateCurrentRoute = () => {
		const currentKey = (window.location.pathname || '') + '?' + (window.location.search || '')
		if (currentKey === lastRouteCheckKey) {
			return
		}
		lastRouteCheckKey = currentKey
		if (isFilesAppRoute()) {
			ensurePublicPadMenuRegistration()
		}
		schedulePublicSingleShareUiStateRefresh()
		void maybeNormalizeStalePadFileRoute()
		void maybeRedirectFromFilesRoute()
		maybeRedirectFromPublicShareRoute()
		sidebarSync.scheduleRefresh(0)
	}

	const installRouteWatchers = () => {
		const flag = 'EtherpadNextcloudRouteWatchInstalled'
		if (window.OCA && window.OCA[flag] === true) {
			return
		}
		if (window.OCA) {
			window.OCA[flag] = true
		}

		const trigger = () => window.setTimeout(evaluateCurrentRoute, 0)
		window.addEventListener('popstate', trigger)
		window.addEventListener('hashchange', trigger)

		const wrapHistoryMethod = (methodName) => {
			const original = window.history && window.history[methodName]
			if (typeof original !== 'function') {
				return
			}
			window.history[methodName] = function (...args) {
				const result = original.apply(this, args)
				trigger()
				return result
			}
		}
		wrapHistoryMethod('pushState')
		wrapHistoryMethod('replaceState')
	}

	const boot = () => {
		if (booted) {
			return
		}
		booted = true
		installRouteWatchers()
		evaluateCurrentRoute()
		registerOpenAction()
		registerPadClickInterceptor()
		sidebarSync.installObserver()
		sidebarSync.scheduleRefresh(200)
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true })
	} else {
		boot()
	}
})()
