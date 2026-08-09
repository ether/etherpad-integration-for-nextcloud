/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
export const parseNumericFileId = (value) => {
	const id = Number(value)
	return Number.isFinite(id) && id > 0 ? id : null
}
