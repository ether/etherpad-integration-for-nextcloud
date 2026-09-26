/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

/**
 * Error bodies the server sends, as the client tests meet them
 * (docs/api-reference.md), so each sentence and code is written once.
 */
export const WAITING = { message: 'This pad is still being restored. Try again later.', code: 'waiting_binding', retryable: true }
export const UNREACHABLE = { message: 'Etherpad cannot be reached right now. Try again later.', retryable: true }
export const LOCKED = { message: 'Pad file is temporarily locked. Please retry.', retryable: true }
export const MISSING_FRONTMATTER = { message: 'Missing YAML frontmatter in .pad file.', code: 'missing_frontmatter' }
export const MISSING_BINDING = { message: 'no binding', code: 'missing_binding' }
export const FILE_CHANGED = { message: 'The file changed while its pad was being set up. Try again.', code: 'pad_file_changed' }

/** What the clients say when nothing came back, untranslated. */
export const UNANSWERED_TEXT = 'Nextcloud did not answer. Check your connection and try again.'
