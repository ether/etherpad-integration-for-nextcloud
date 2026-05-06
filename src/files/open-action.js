/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import { APP_ID, MIME } from '../lib/constants.js'
import {
	ocImagePath,
	ocPermissionRead,
} from '../lib/oc-compat.js'
import {
	getCurrentDir,
	normalizeFilePath,
	parseFileIdFromFilesHref,
} from '../lib/urls.js'

const OPEN_ACTION = 'etherpad_nextcloud_open'

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

export const extractFileIdFromActionContext = (context) => {
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

export const registerOpenAction = ({ openPadInNativeViewer }) => {
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
