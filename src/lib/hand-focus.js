/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * After a click whose button went away with the focus on it - a second
 * try, a recovery - the first action in `actionsScope`, or else the
 * message, takes the focus, so a keyboard or screen reader keeps its
 * place (docs/architecture.md, "Errors of the API").
 *
 * @param {Element|null|undefined} actionsScope where the new actions are
 * @param {Element|null|undefined} messageNode what to focus without one
 * @param {{preventScroll?: boolean}} [options] the embed page sits in
 *   another page, which must not scroll to it
 */
export const handFocusTo = (actionsScope, messageNode, { preventScroll = false } = {}) => {
	const action = actionsScope instanceof HTMLElement ? actionsScope.querySelector('a, button') : null
	const target = action || messageNode
	if (!(target instanceof HTMLElement)) {
		return
	}
	if (target === messageNode) {
		// A paragraph takes the focus only with a tabindex.
		target.tabIndex = -1
	}
	target.focus({ preventScroll })
}
