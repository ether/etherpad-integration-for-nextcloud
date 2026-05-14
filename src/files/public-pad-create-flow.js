/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
import {
	apiCreatePadFromUrl,
	apiCreatePublicPad,
} from '../lib/api-client.js'
import {
	getCurrentDir,
	isPadName,
	normalizeFilePath,
	resolveOpenDir,
} from '../lib/urls.js'
import { openCreatedPadInViewer } from './created-pad-opener.js'
import {
	openExternalPublicPadDialog,
	openInternalPublicPadDialog,
} from './pad-create-dialogs.js'

const ensurePadExtension = (name) => isPadName(name) ? name : (name + '.pad')

const createdPadNavigation = (created, fallbackPath) => ({
	path: (created && typeof created.file === 'string') ? created.file : fallbackPath,
	fileId: created && Number.isFinite(Number(created.file_id)) ? Number(created.file_id) : null,
})

export const createPublicPadFlows = ({ openPadInNativeViewer }) => {
	const openCreatedPad = (created, fallbackPath) => openCreatedPadInViewer(
		createdPadNavigation(created, fallbackPath),
		{
			fallbackOpen: openPadInNativeViewer,
			resolveOpenDir,
		}
	)

	/**
	 * Called when the user picks the internal-public-pad NewFileMenu entry.
	 * NC does not reliably trigger its own inline-rename UI for handler-based
	 * entries, so we own the prompt here. The dialog stays open during the
	 * create call and renders backend errors inline (including the 409
	 * "A file with this name already exists." on the duplicate-name path).
	 */
	const createInternalPublicPad = async () => {
		await openInternalPublicPadDialog({
			onSubmit: async (name) => {
				const filePath = normalizeFilePath(getCurrentDir(), ensurePadExtension(name.trim()))
				const created = await apiCreatePublicPad(filePath)
				await openCreatedPad(created, filePath)
				return created
			},
		})
	}

	/**
	 * Called when the user picks the external-public-pad NewFileMenu entry.
	 * Opens a dialog for the URL + name. The dialog stays open while the
	 * create call is in flight and surfaces backend errors (including the 409
	 * "A file with this name already exists." message) inline so the user can
	 * adjust the name without losing the URL.
	 */
	const createExternalPublicPad = async () => {
		await openExternalPublicPadDialog({
			onSubmit: async ({ padUrl, name }) => {
				const trimmedUrl = padUrl.trim()
				const filePath = normalizeFilePath(getCurrentDir(), ensurePadExtension(name.trim()))
				const created = await apiCreatePadFromUrl(filePath, trimmedUrl)
				await openCreatedPad(created, filePath)
				return created
			},
		})
	}

	return {
		createInternalPublicPad,
		createExternalPublicPad,
	}
}
