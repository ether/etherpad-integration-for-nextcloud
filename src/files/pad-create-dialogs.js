/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

import { APP_ID } from '../lib/constants.js'

const suggestFileNameFromPadUrl = (padUrl) => {
	try {
		const url = new URL(padUrl, window.location.origin)
		const decoded = decodeURIComponent(url.pathname || '')
		const match = decoded.match(/\/p\/([^/]+)$/)
		if (match && match[1]) {
			const safe = match[1].replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')
			if (safe !== '') {
				return safe + '.pad'
			}
		}
	} catch (e) {
		// fallback below
	}
	return 'Imported pad.pad'
}

const createModalScaffold = (titleText) => {
	const overlay = document.createElement('div')
	overlay.style.position = 'fixed'
	overlay.style.inset = '0'
	overlay.style.background = 'rgba(0, 0, 0, 0.45)'
	overlay.style.display = 'flex'
	overlay.style.alignItems = 'center'
	overlay.style.justifyContent = 'center'
	overlay.style.zIndex = '20000'

	const dialog = document.createElement('div')
	dialog.style.position = 'relative'
	dialog.style.background = 'var(--color-main-background, #fff)'
	dialog.style.borderRadius = '10px'
	dialog.style.boxShadow = '0 10px 30px rgba(0, 0, 0, 0.25)'
	dialog.style.padding = '18px'
	dialog.style.width = 'min(460px, calc(100vw - 24px))'

	const closeButton = document.createElement('button')
	closeButton.type = 'button'
	closeButton.setAttribute('aria-label', t(APP_ID, 'Close'))
	closeButton.textContent = '×'
	closeButton.style.position = 'absolute'
	closeButton.style.top = '8px'
	closeButton.style.right = '10px'
	closeButton.style.border = 'none'
	closeButton.style.background = 'transparent'
	closeButton.style.fontSize = '22px'
	closeButton.style.cursor = 'pointer'
	closeButton.style.lineHeight = '1'

	const title = document.createElement('h3')
	title.textContent = titleText
	title.style.margin = '0 26px 10px 0'
	title.style.fontSize = '18px'

	dialog.appendChild(closeButton)
	dialog.appendChild(title)
	overlay.appendChild(dialog)
	document.body.appendChild(overlay)

	return { overlay, dialog, closeButton }
}

/**
 * Dialog for creating an internal public pad. NC's NewFileMenu does not
 * reliably trigger its own inline-rename UI for handler-based entries, so we
 * own the name prompt here. The dialog stays open during submission and shows
 * the backend error inline on failure.
 *
 * @param {object} options
 * @param {(name: string) => Promise<*>} options.onSubmit
 * @returns {Promise<*|null>}
 */
export const openInternalPublicPadDialog = ({ onSubmit }) => new Promise((resolve) => {
	const { overlay, dialog, closeButton } = createModalScaffold(t(APP_ID, 'Public pad'))

	const nameLabel = document.createElement('label')
	nameLabel.textContent = t(APP_ID, 'File name')
	nameLabel.style.display = 'block'
	nameLabel.style.marginBottom = '6px'

	const nameInput = document.createElement('input')
	nameInput.type = 'text'
	nameInput.value = t(APP_ID, 'Public pad') + '.pad'
	nameInput.style.width = '100%'
	nameInput.style.boxSizing = 'border-box'
	nameInput.style.marginBottom = '12px'

	const error = document.createElement('p')
	error.style.color = 'var(--color-error, #c62828)'
	error.style.margin = '0 0 12px 0'
	error.style.minHeight = '20px'

	const createButton = document.createElement('button')
	createButton.type = 'button'
	createButton.className = 'primary'
	createButton.textContent = t(APP_ID, 'Create')

	let isPending = false
	const close = (result) => {
		if (isPending) {
			return
		}
		overlay.remove()
		resolve(result)
	}
	closeButton.addEventListener('click', () => close(null))
	overlay.addEventListener('click', (event) => {
		if (event.target === overlay) {
			close(null)
		}
	})

	const setPending = (pending) => {
		isPending = pending
		createButton.disabled = pending
		nameInput.disabled = pending
		closeButton.disabled = pending
		createButton.textContent = pending ? t(APP_ID, 'Creating...') : t(APP_ID, 'Create')
	}

	const submit = async () => {
		const name = nameInput.value.trim()
		if (name === '') {
			error.textContent = t(APP_ID, 'File name is required.')
			nameInput.focus()
			return
		}
		error.textContent = ''
		setPending(true)
		try {
			const result = await onSubmit(name)
			setPending(false)
			close(result)
		} catch (e) {
			setPending(false)
			error.textContent = e instanceof Error && e.message !== ''
				? e.message
				: t(APP_ID, 'Could not create public pad.')
			nameInput.focus()
			nameInput.select()
		}
	}

	nameInput.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			void submit()
		}
	})
	createButton.addEventListener('click', () => {
		void submit()
	})

	dialog.appendChild(nameLabel)
	dialog.appendChild(nameInput)
	dialog.appendChild(error)
	dialog.appendChild(createButton)
	nameInput.focus()
	nameInput.select()
})

/**
 * Dialog for importing an external public pad. Keeps the URL field, the name
 * field, and shares the same loading-state + inline-error pattern.
 *
 * @param {object} options
 * @param {(values: {padUrl: string, name: string}) => Promise<*>} options.onSubmit
 *   called when the user confirms; resolves to close the dialog with its
 *   value, rejects to keep the dialog open and show the error message inline.
 * @returns {Promise<*|null>} the resolved onSubmit value, or null if the user cancelled.
 */
export const openExternalPublicPadDialog = ({ onSubmit }) => new Promise((resolve) => {
	const { overlay, dialog, closeButton } = createModalScaffold(t(APP_ID, 'Public pad from URL'))

	const urlLabel = document.createElement('label')
	urlLabel.textContent = t(APP_ID, 'External pad URL')
	urlLabel.style.display = 'block'
	urlLabel.style.marginBottom = '6px'

	const urlInput = document.createElement('input')
	urlInput.type = 'url'
	urlInput.value = 'https://'
	urlInput.placeholder = 'https://'
	urlInput.style.width = '100%'
	urlInput.style.boxSizing = 'border-box'
	urlInput.style.marginBottom = '12px'

	const nameLabel = document.createElement('label')
	nameLabel.textContent = t(APP_ID, 'File name')
	nameLabel.style.display = 'block'
	nameLabel.style.marginBottom = '6px'

	const nameInput = document.createElement('input')
	nameInput.type = 'text'
	nameInput.value = 'Imported pad.pad'
	nameInput.style.width = '100%'
	nameInput.style.boxSizing = 'border-box'
	nameInput.style.marginBottom = '12px'

	const error = document.createElement('p')
	error.style.color = 'var(--color-error, #c62828)'
	error.style.margin = '0 0 12px 0'
	error.style.minHeight = '20px'

	const createButton = document.createElement('button')
	createButton.type = 'button'
	createButton.className = 'primary'
	createButton.textContent = t(APP_ID, 'Create')

	let isPending = false
	const close = (result) => {
		if (isPending) {
			return
		}
		overlay.remove()
		resolve(result)
	}
	closeButton.addEventListener('click', () => close(null))
	overlay.addEventListener('click', (event) => {
		if (event.target === overlay) {
			close(null)
		}
	})
	urlInput.addEventListener('blur', () => {
		const candidate = urlInput.value.trim()
		if (candidate.startsWith('http')) {
			nameInput.value = suggestFileNameFromPadUrl(candidate)
		}
	})

	const setPending = (pending) => {
		isPending = pending
		createButton.disabled = pending
		urlInput.disabled = pending
		nameInput.disabled = pending
		closeButton.disabled = pending
		createButton.textContent = pending ? t(APP_ID, 'Creating...') : t(APP_ID, 'Create')
	}

	const submit = async () => {
		const padUrl = urlInput.value.trim()
		const name = nameInput.value.trim()
		if (padUrl === '') {
			error.textContent = t(APP_ID, 'External pad URL is required.')
			urlInput.focus()
			return
		}
		if (name === '') {
			error.textContent = t(APP_ID, 'File name is required.')
			nameInput.focus()
			return
		}
		error.textContent = ''
		setPending(true)
		try {
			const result = await onSubmit({ padUrl, name })
			setPending(false)
			close(result)
		} catch (e) {
			setPending(false)
			error.textContent = e instanceof Error && e.message !== ''
				? e.message
				: t(APP_ID, 'Could not import public pad URL.')
			nameInput.focus()
			nameInput.select()
		}
	}

	urlInput.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			void submit()
		}
	})
	nameInput.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			void submit()
		}
	})
	createButton.addEventListener('click', () => {
		void submit()
	})

	dialog.appendChild(urlLabel)
	dialog.appendChild(urlInput)
	dialog.appendChild(nameLabel)
	dialog.appendChild(nameInput)
	dialog.appendChild(error)
	dialog.appendChild(createButton)
	urlInput.focus()
	urlInput.select()
})
